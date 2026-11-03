<?php

namespace App\Services\Societe;

use App\Services\Panel\ActividadesService;
use App\Services\Panel\PanelDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Estadísticas del listado de terceros en función de su actividad comercial: solicitudes, cotizaciones y órdenes de
 * compra activas y seguimientos pendientes. Mismas reglas del tablero: documentos activos creados desde
 * INFORMESSOLU_ANIO_DESDE; los administradores ven todo y los demás solo lo propio. condicionActividad() entrega el
 * filtro EXISTS equivalente para que el grid muestre exactamente los terceros que cuenta cada tarjeta.
 */
class TercerosEstadisticasService
{
    public const TIPOS = ['solicitudes', 'cotizaciones', 'ordenes', 'seguimientos', 'actividad'];

    public function __construct(private PanelDashboardService $dashboard, private ActividadesService $actividades)
    {
    }

    private function docs(): array
    {
        $p = DB::getTablePrefix();
        $cot = 'CASE WHEN COALESCE(d.tipo_moneda,0)=1 AND COALESCE(d.trm,0)>0 THEN COALESCE(d.total_bruto,0) * d.trm ELSE COALESCE(d.total_bruto,0) END';
        $oc = 'CASE WHEN COALESCE(cc.tipo_moneda,0)=1 AND COALESCE(cc.trm,0)>0 THEN COALESCE(oe.total_productos, d.total_bruto, 0) * cc.trm ELSE COALESCE(oe.total_productos, d.total_bruto, 0) END';

        return [
            'solicitudes' => ['from' => "{$p}solicitudes_solicitud d", 'activo' => 'd.status BETWEEN 1 AND 6', 'creado' => 'd.status > 0', 'valor' => null],
            'cotizaciones' => ['from' => "{$p}cotizaciones_clientecotizacion d", 'activo' => 'd.status IN (1,2)', 'creado' => 'd.status > 0', 'valor' => $cot],
            'ordenes' => ['from' => "{$p}ordencompra_orden d LEFT JOIN {$p}ordencompra_orden_extrafields oe ON oe.fk_object = d.rowid LEFT JOIN {$p}cotizaciones_clientecotizacion cc ON cc.rowid = d.fk_cliente_cotizacion",
                'activo' => 'd.status NOT IN (0,5,9,10,31)', 'creado' => 'd.status NOT IN (0,31)', 'valor' => $oc],
        ];
    }

    public function resumen(object $usuario): array
    {
        $admin = (bool) $usuario->isAdmin();
        $uid = $admin ? 0 : (int) $usuario->getKey();

        return Cache::remember("terceros-estadisticas:{$uid}", 120, function () use ($admin, $uid, $usuario) {
            $p = DB::getTablePrefix();
            $uf = $this->actividades->campoUsuario();
            $anio = $this->dashboard->anioDesde();
            $piso = "{$anio}-01-01";
            $hoy = Carbon::now();
            $duenio = $admin ? '' : " AND d.{$uf} = {$uid}";

            $ini30 = $hoy->copy()->subDays(29)->startOfDay();
            $ini60 = $hoy->copy()->subDays(59)->startOfDay();
            $desde12 = $hoy->copy()->subMonths(11)->startOfMonth();
            $desdeSerie = $desde12->lt(Carbon::parse($piso)) ? Carbon::parse($piso) : $desde12;

            $tarjetas = [];
            foreach ($this->docs() as $clave => $def) {
                $a = DB::selectOne("SELECT COUNT(*) n, COUNT(DISTINCT d.fk_soc) terceros, ".($def['valor'] ? "COALESCE(SUM({$def['valor']}),0)" : '0')." valor
                    FROM {$def['from']} WHERE {$def['activo']} AND d.date_creation >= ?{$duenio}", [$piso]);
                $r = DB::selectOne("SELECT COALESCE(SUM(d.date_creation >= ?),0) actual, COALESCE(SUM(d.date_creation >= ? AND d.date_creation < ?),0) previo
                    FROM {$def['from']} WHERE {$def['creado']} AND d.date_creation >= ?{$duenio}", [$ini30->toDateTimeString(), $ini60->toDateTimeString(), $ini30->toDateTimeString(), $ini60->toDateTimeString()]);
                $porMes = collect(DB::select("SELECT DATE_FORMAT(d.date_creation,'%Y-%m') k, COUNT(*) n FROM {$def['from']} WHERE {$def['creado']} AND d.date_creation >= ?{$duenio} GROUP BY k", [$desdeSerie->toDateTimeString()]))->keyBy('k');
                $serie = [];
                for ($c = $desdeSerie->copy()->startOfMonth(); $c <= $hoy; $c->addMonth()) {
                    $serie[] = (int) ($porMes[$c->format('Y-m')]->n ?? 0);
                }

                $tarjetas[$clave] = [
                    'activos' => (int) $a->n, 'terceros' => (int) $a->terceros, 'valor' => $def['valor'] ? round((float) $a->valor) : null,
                    'creados30' => (int) $r->actual, 'creados30Previo' => (int) $r->previo, 'serie' => $serie,
                ];
            }

            $hoyLocal = Carbon::now(ActividadesService::TZ)->toDateString();
            $dueSeg = $admin ? '' : " AND (a.fk_user_author = {$uid} OR a.fk_user_action = {$uid})";
            $s = DB::selectOne("SELECT COUNT(*) n, COUNT(DISTINCT NULLIF(a.fk_soc,0)) terceros,
                    COALESCE(SUM(DATE(a.datep) < ?),0) atrasados, COALESCE(SUM(DATE(a.datep) = ?),0) hoy
                FROM {$p}actioncomm a WHERE a.code LIKE ? AND a.percent < 100 AND a.elementtype IN ('solicitud','clientecotizacion','orden'){$dueSeg}",
                [$hoyLocal, $hoyLocal, 'AC\_SEGUIMIENTO\_RECORDATORIO%']);
            $tarjetas['seguimientos'] = ['activos' => (int) $s->n, 'terceros' => (int) $s->terceros, 'atrasados' => (int) $s->atrasados, 'hoy' => (int) $s->hoy];

            [$sqlAct, $bAct] = $this->condicionActividad('actividad', $usuario);
            $conActividad = (int) DB::selectOne("SELECT COUNT(*) n FROM {$p}societe s WHERE s.status = 1 AND {$sqlAct}", $bAct)->n;
            $colores = $this->dashboard->coloresEtapas();

            return [
                'alcance' => $admin ? 'todos' : 'mios', 'desdeAnio' => $anio,
                'totalTerceros' => (int) DB::selectOne("SELECT COUNT(*) n FROM {$p}societe WHERE status = 1")->n,
                'conActividad' => $conActividad,
                'colores' => ['solicitudes' => $colores[1]['fondo'], 'cotizaciones' => $colores[2]['fondo'], 'ordenes' => $colores[3]['fondo']],
                'tarjetas' => $tarjetas, 'generado' => $hoy->format('H:i:s'),
            ];
        });
    }

    /**
     * Filtro EXISTS (alias s = societe) con sus bindings para el grid de terceros. "actividad" = cualquiera de los cuatro.
     *
     * @return array{0: string, 1: array}
     */
    public function condicionActividad(string $tipo, object $usuario): array
    {
        abort_unless(in_array($tipo, self::TIPOS, true), 422, 'Filtro de actividad inválido.');

        if ($tipo === 'actividad') {
            $partes = array_map(fn ($t) => $this->parteActividad($t, $usuario), array_diff(self::TIPOS, ['actividad']));

            return ['('.implode(' OR ', array_column($partes, 0)).')', array_merge(...array_column($partes, 1))];
        }

        return $this->parteActividad($tipo, $usuario);
    }

    private function parteActividad(string $tipo, object $usuario): array
    {
        $p = DB::getTablePrefix();
        $admin = (bool) $usuario->isAdmin();
        $uid = (int) $usuario->getKey();
        $piso = $this->dashboard->anioDesde().'-01-01';

        if ($tipo === 'seguimientos') {
            $sql = "EXISTS (SELECT 1 FROM {$p}actioncomm a WHERE a.fk_soc = s.rowid AND a.code LIKE ? AND a.percent < 100 AND a.elementtype IN ('solicitud','clientecotizacion','orden')"
                .($admin ? '' : ' AND (a.fk_user_author = ? OR a.fk_user_action = ?)').')';

            return [$sql, array_merge(['AC\_SEGUIMIENTO\_RECORDATORIO%'], $admin ? [] : [$uid, $uid])];
        }

        $def = $this->docs()[$tipo];
        $uf = $this->actividades->campoUsuario();
        $sql = "EXISTS (SELECT 1 FROM {$def['from']} WHERE d.fk_soc = s.rowid AND {$def['activo']} AND d.date_creation >= ?".($admin ? '' : " AND d.{$uf} = ?").')';

        return [$sql, array_merge([$piso], $admin ? [] : [$uid])];
    }
}
