<?php

namespace App\Services\Panel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tarjetas sobre el listado de solicitudes: activas, en proceso, en cotización y en órdenes. Cada una es un grupo
 * de estados de la solicitud; condicion() entrega el mismo filtro para que el grid muestre exactamente lo que cuenta la tarjeta.
 * Mismas reglas del tablero: documentos creados desde INFORMESSOLU_ANIO_DESDE; los administradores ven todo y los demás lo propio.
 */
class SolicitudesEstadisticasService
{
    /** Estados de la solicitud (ver SolicitudesGridController::ESTADOS) que cuenta cada tarjeta. */
    public const GRUPOS = [
        'activas' => [1, 2, 3, 4, 5, 6],                          // las mismas «activas» del tablero
        'proceso' => [2, 3, 4, 6],                                // pedidas a proveedor (cotización exterior, local o USA) o en importación
        'cotizaciones' => [5, 22, 23, 24],                        // cotización enviada o en cotización exterior, local o USA
        'ordenes' => [10, 11, 12, 13, 14, 15, 16, 17, 18, 19],    // órdenes de compra vigentes (sin las cerradas ni canceladas)
    ];


    public function __construct(private PanelDashboardService $dashboard, private ActividadesService $actividades)
    {
    }

    /** Filtro SQL (alias t = solicitudes_solicitud) de una tarjeta. Los estados son constantes propias, no entrada del usuario. */
    public function condicion(string $clave): string
    {
        abort_unless(isset(self::GRUPOS[$clave]), 422, 'Filtro inválido.');

        return 't.status IN ('.implode(',', self::GRUPOS[$clave]).')';
    }

    public function resumen(object $usuario): array
    {
        $admin = (bool) $usuario->isAdmin();
        $uid = $admin ? 0 : (int) $usuario->getKey();

        return Cache::remember("solicitudes-estadisticas:{$uid}", 120, function () use ($admin, $uid) {
            $p = DB::getTablePrefix();
            $uf = $this->actividades->campoUsuario();
            $anio = $this->dashboard->anioDesde();
            $piso = "{$anio}-01-01";
            $hoy = Carbon::now();
            $duenio = $admin ? '' : " AND t.{$uf} = {$uid}";
            $ini30 = $hoy->copy()->subDays(29)->startOfDay()->toDateTimeString();
            $ini60 = $hoy->copy()->subDays(59)->startOfDay()->toDateTimeString();
            $desde12 = $hoy->copy()->subMonths(11)->startOfMonth();
            $desdeSerie = $desde12->lt(Carbon::parse($piso)) ? Carbon::parse($piso) : $desde12;

            $tarjetas = [];
            foreach (array_keys(self::GRUPOS) as $clave) {
                $cond = $this->condicion($clave);
                $a = DB::selectOne("SELECT COUNT(*) n, COUNT(DISTINCT t.fk_soc) clientes,
                        COALESCE(SUM(DATEDIFF(CURDATE(), DATE(t.date_creation)) > 30),0) atrasadas,
                        COALESCE(SUM(t.date_creation >= ?),0) creados30,
                        COALESCE(SUM(t.date_creation >= ? AND t.date_creation < ?),0) previo
                    FROM {$p}solicitudes_solicitud t WHERE {$cond} AND t.date_creation >= ?{$duenio}", [$ini30, $ini60, $ini30, $piso]);
                $porMes = collect(DB::select("SELECT DATE_FORMAT(t.date_creation,'%Y-%m') k, COUNT(*) n FROM {$p}solicitudes_solicitud t
                    WHERE {$cond} AND t.date_creation >= ?{$duenio} GROUP BY k", [$desdeSerie->toDateTimeString()]))->keyBy('k');
                $serie = [];
                for ($c = $desdeSerie->copy()->startOfMonth(); $c <= $hoy; $c->addMonth()) {
                    $serie[] = (int) ($porMes[$c->format('Y-m')]->n ?? 0);
                }
                $tarjetas[$clave] = [
                    'total' => (int) $a->n, 'clientes' => (int) $a->clientes, 'atrasadas' => (int) $a->atrasadas,
                    'creados30' => (int) $a->creados30, 'creados30Previo' => (int) $a->previo, 'serie' => $serie,
                ];
            }

            $colores = $this->dashboard->coloresEtapas();

            return [
                'alcance' => $admin ? 'todos' : 'mios', 'desdeAnio' => $anio,
                'colores' => ['activas' => $colores[1]['fondo'], 'proceso' => '#f59e0b', 'cotizaciones' => $colores[2]['fondo'], 'ordenes' => $colores[3]['fondo']],
                'tarjetas' => $tarjetas, 'generado' => $hoy->format('H:i:s'),
            ];
        });
    }
}
