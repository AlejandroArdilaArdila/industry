<?php

namespace App\Services\Productos;

use App\Services\Panel\ActividadesService;
use App\Services\Panel\PanelDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Estadísticas del listado de productos según su actividad comercial. Un producto "está" en un documento cuando
 * aparece en una de sus líneas (solicitudes_solicitudline: cada línea enlaza a la solicitud y a la cotización; la
 * orden de compra se enlaza por su cotización). Mismas reglas del tablero: documentos activos creados desde
 * INFORMESSOLU_ANIO_DESDE; los administradores ven todo y los demás solo lo propio. subconsulta() sirve tanto para
 * contar como para el filtro del grid, así el número de cada tarjeta coincide con las filas filtradas.
 */
class ProductosEstadisticasService
{
    public const TIPOS = ['solicitudes', 'cotizaciones', 'ordenes', 'seguimientos', 'actividad'];

    private const DOCS = ['solicitudes', 'cotizaciones', 'ordenes'];

    public function __construct(private PanelDashboardService $dashboard, private ActividadesService $actividades)
    {
    }

    private function def(string $tipo): array
    {
        $p = DB::getTablePrefix();

        return match ($tipo) {
            'solicitudes' => ['join' => "INNER JOIN {$p}solicitudes_solicitud d ON d.rowid = l.fk_solicitud", 'activo' => 'd.status BETWEEN 1 AND 6', 'creado' => 'd.status > 0'],
            'cotizaciones' => ['join' => "INNER JOIN {$p}cotizaciones_clientecotizacion d ON d.rowid = l.fk_cotizacion", 'activo' => 'd.status IN (1,2)', 'creado' => 'd.status > 0'],
            default => ['join' => "INNER JOIN {$p}ordencompra_orden d ON d.fk_cliente_cotizacion = l.fk_cotizacion", 'activo' => 'd.status NOT IN (0,5,9,10,31)', 'creado' => 'd.status NOT IN (0,31)'],
        };
    }

    /**
     * SELECT sobre las líneas de los documentos activos de un tipo.
     *
     * @return array{0: string, 1: array}
     */
    private function lineasActivas(string $tipo, string $select, bool $admin, int $uid): array
    {
        $p = DB::getTablePrefix();
        $d = $this->def($tipo);
        $uf = $this->actividades->campoUsuario();
        $sql = "SELECT {$select} FROM {$p}solicitudes_solicitudline l {$d['join']} WHERE l.fk_product > 0 AND {$d['activo']} AND d.date_creation >= ?".($admin ? '' : " AND d.{$uf} = ?");

        return [$sql, array_merge([$this->dashboard->anioDesde().'-01-01'], $admin ? [] : [$uid])];
    }

    /** Productos (ids) en documentos con seguimientos pendientes; parte de la agenda, que es lo que filtra más. */
    private function productosSeguimientos(bool $admin, int $uid): array
    {
        $p = DB::getTablePrefix();
        $dueno = $admin ? '' : ' AND (a.fk_user_author = ? OR a.fk_user_action = ?)';
        $b = fn () => array_merge(['AC\_SEGUIMIENTO\_RECORDATORIO%'], $admin ? [] : [$uid, $uid]);
        $filtro = "a.code LIKE ? AND a.percent < 100{$dueno} AND l.fk_product > 0";

        $partes = [
            "SELECT l.fk_product FROM {$p}actioncomm a INNER JOIN {$p}solicitudes_solicitudline l ON l.fk_solicitud = a.fk_element WHERE a.elementtype = 'solicitud' AND {$filtro}",
            "SELECT l.fk_product FROM {$p}actioncomm a INNER JOIN {$p}solicitudes_solicitudline l ON l.fk_cotizacion = a.fk_element WHERE a.elementtype = 'clientecotizacion' AND {$filtro}",
            "SELECT l.fk_product FROM {$p}actioncomm a INNER JOIN {$p}ordencompra_orden d ON d.rowid = a.fk_element INNER JOIN {$p}solicitudes_solicitudline l ON l.fk_cotizacion = d.fk_cliente_cotizacion WHERE a.elementtype = 'orden' AND {$filtro}",
        ];

        return [implode(' UNION ', $partes), array_merge($b(), $b(), $b())];
    }

    /**
     * Subconsulta de ids de producto de un tipo concreto (no "actividad").
     *
     * @return array{0: string, 1: array}
     */
    public function subconsulta(string $tipo, object $usuario): array
    {
        abort_unless(in_array($tipo, self::TIPOS, true) && $tipo !== 'actividad', 422, 'Filtro de actividad inválido.');
        $admin = (bool) $usuario->isAdmin();
        $uid = (int) $usuario->getKey();

        return $tipo === 'seguimientos' ? $this->productosSeguimientos($admin, $uid) : $this->lineasActivas($tipo, 'l.fk_product', $admin, $uid);
    }

    /**
     * Condición "p.rowid IN (...)" para el grid y los conteos. "actividad" une los cuatro tipos con OR de IN: es la forma
     * que el optimizador de MySQL resuelve bien (un IN sobre UNION anidado se vuelve correlacionado y tarda minutos).
     *
     * @return array{0: string, 1: array}
     */
    public function condicion(string $tipo, object $usuario): array
    {
        abort_unless(in_array($tipo, self::TIPOS, true), 422, 'Filtro de actividad inválido.');
        $tipos = $tipo === 'actividad' ? array_merge(self::DOCS, ['seguimientos']) : [$tipo];
        $conds = [];
        $bind = [];
        foreach ($tipos as $t) {
            [$sub, $b] = $this->subconsulta($t, $usuario);
            $conds[] = "p.rowid IN ({$sub})";
            array_push($bind, ...$b);
        }

        return ['('.implode(' OR ', $conds).')', $bind];
    }

    public function resumen(object $usuario): array
    {
        $admin = (bool) $usuario->isAdmin();
        $uid = $admin ? 0 : (int) $usuario->getKey();

        return Cache::remember("productos-estadisticas:{$uid}", 300, function () use ($admin, $uid, $usuario) {
            $p = DB::getTablePrefix();
            $uf = $this->actividades->campoUsuario();
            $piso = $this->dashboard->anioDesde().'-01-01';
            $hoy = Carbon::now();
            $ini30 = $hoy->copy()->subDays(29)->startOfDay();
            $ini60 = $hoy->copy()->subDays(59)->startOfDay();
            $desde12 = $hoy->copy()->subMonths(11)->startOfMonth();
            $desdeSerie = $desde12->lt(Carbon::parse($piso)) ? Carbon::parse($piso) : $desde12;
            $dueno = $admin ? '' : " AND d.{$uf} = {$uid}";

            $tarjetas = [];
            foreach (self::DOCS as $tipo) {
                $d = $this->def($tipo);
                [$cond, $b] = $this->condicion($tipo, $usuario);
                $productos = (int) DB::selectOne("SELECT COUNT(*) n FROM {$p}product p WHERE p.fk_product_type = 0 AND {$cond}", $b)->n;
                [$sub2, $b2] = $this->lineasActivas($tipo, 'COALESCE(SUM(l.qty),0) unidades, COUNT(DISTINCT d.rowid) documentos, COUNT(*) lineas', $admin, $uid);
                $t = DB::selectOne($sub2, $b2);

                $r = DB::selectOne("SELECT COUNT(DISTINCT CASE WHEN d.date_creation >= ? THEN l.fk_product END) actual,
                        COUNT(DISTINCT CASE WHEN d.date_creation >= ? AND d.date_creation < ? THEN l.fk_product END) previo
                    FROM {$p}solicitudes_solicitudline l {$d['join']} WHERE l.fk_product > 0 AND {$d['creado']} AND d.date_creation >= ?{$dueno}",
                    [$ini30->toDateTimeString(), $ini60->toDateTimeString(), $ini30->toDateTimeString(), $ini60->toDateTimeString()]);

                $porMes = collect(DB::select("SELECT DATE_FORMAT(d.date_creation,'%Y-%m') k, COUNT(DISTINCT l.fk_product) n FROM {$p}solicitudes_solicitudline l {$d['join']}
                    WHERE l.fk_product > 0 AND {$d['creado']} AND d.date_creation >= ?{$dueno} GROUP BY k", [$desdeSerie->toDateTimeString()]))->keyBy('k');
                $serie = [];
                for ($c = $desdeSerie->copy()->startOfMonth(); $c <= $hoy; $c->addMonth()) {
                    $serie[] = (int) ($porMes[$c->format('Y-m')]->n ?? 0);
                }

                $tarjetas[$tipo] = ['productos' => $productos, 'unidades' => round((float) $t->unidades), 'documentos' => (int) $t->documentos,
                    'nuevos30' => (int) $r->actual, 'nuevos30Previo' => (int) $r->previo, 'serie' => $serie];
            }

            [$condSeg, $bSeg] = $this->condicion('seguimientos', $usuario);
            $hoyLocal = Carbon::now(ActividadesService::TZ)->toDateString();
            $dueSeg = $admin ? '' : " AND (a.fk_user_author = {$uid} OR a.fk_user_action = {$uid})";
            $s = DB::selectOne("SELECT COUNT(*) n, COALESCE(SUM(DATE(a.datep) < ?),0) atrasados, COALESCE(SUM(DATE(a.datep) = ?),0) hoy
                FROM {$p}actioncomm a WHERE a.code LIKE ? AND a.percent < 100 AND a.elementtype IN ('solicitud','clientecotizacion','orden'){$dueSeg}", [$hoyLocal, $hoyLocal, 'AC\_SEGUIMIENTO\_RECORDATORIO%']);
            $tarjetas['seguimientos'] = [
                'productos' => (int) DB::selectOne("SELECT COUNT(*) n FROM {$p}product p WHERE p.fk_product_type = 0 AND {$condSeg}", $bSeg)->n,
                'recordatorios' => (int) $s->n, 'atrasados' => (int) $s->atrasados, 'hoy' => (int) $s->hoy,
            ];

            [$condAct, $bAct] = $this->condicion('actividad', $usuario);
            $colores = $this->dashboard->coloresEtapas();

            return [
                'alcance' => $admin ? 'todos' : 'mios', 'desdeAnio' => $this->dashboard->anioDesde(),
                'totalProductos' => (int) DB::selectOne("SELECT COUNT(*) n FROM {$p}product WHERE fk_product_type = 0")->n,
                'conActividad' => (int) DB::selectOne("SELECT COUNT(*) n FROM {$p}product p WHERE p.fk_product_type = 0 AND {$condAct}", $bAct)->n,
                'colores' => ['solicitudes' => $colores[1]['fondo'], 'cotizaciones' => $colores[2]['fondo'], 'ordenes' => $colores[3]['fondo']],
                'tarjetas' => $tarjetas, 'generado' => $hoy->format('H:i:s'),
            ];
        });
    }
}
