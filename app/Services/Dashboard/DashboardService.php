<?php

namespace App\Services\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Puerto a Laravel de las acciones "dashboard_listar" / "widget_ejecutar" de
 * htdocs/custom/informes/lib/dashboard_ajax.php: dashboards guardados por el
 * constructor visual "Dashboard Dinámico" (tablas informes_dashboard /
 * informes_dashboard_widget), cada widget con su propio SQL (sql_generado) y
 * variables {{fecha_desde}}/{{fecha_hasta}}/etc. que se interpolan antes de
 * ejecutar.
 *
 * A diferencia del "datos" AJAX original (un widget por llamada, sin período
 * de comparación), este servicio trae TODOS los widgets de un dashboard en un
 * solo request y agrega período de comparación opcional (fecha_desde_comp /
 * fecha_hasta_comp), pedido por la App: cada widget corre dos veces (período
 * actual y, si vino, período de comparación) con el mismo SQL guardado.
 */
class DashboardService
{
    private const CONNECTION = 'mysql';

    public function listarDashboards(int $userId): array
    {
        // Sin alias cortos ("informes_dashboard as d"): con DB_PREFIX configurado, el query
        // builder de Laravel prefija también el alias (queda "llx_d"), lo que rompe cualquier
        // referencia a "d.columna" en SQL crudo (DB::raw) que no pasa por el grammar.
        $prefix = DB::connection(self::CONNECTION)->getTablePrefix();

        $rows = DB::connection(self::CONNECTION)
            ->table('informes_dashboard')
            ->leftJoin('user', 'user.rowid', '=', 'informes_dashboard.fk_user_creat')
            ->where(function ($q) use ($userId) {
                $q->where('informes_dashboard.fk_user_creat', $userId)->orWhere('informes_dashboard.es_publico', 1);
            })
            ->orderByDesc('informes_dashboard.tms')
            ->select([
                'informes_dashboard.rowid', 'informes_dashboard.nombre', 'informes_dashboard.descripcion',
                'informes_dashboard.color_tema', 'informes_dashboard.icono', 'informes_dashboard.es_publico',
                'informes_dashboard.fk_user_creat', 'informes_dashboard.date_creation', 'informes_dashboard.tms',
                'user.firstname as autor_nombre', 'user.lastname as autor_apellido',
                DB::raw("(SELECT COUNT(*) FROM {$prefix}informes_dashboard_widget w WHERE w.fk_dashboard = {$prefix}informes_dashboard.rowid) AS n_widgets"),
            ])
            ->get();

        $items = [];
        foreach ($rows as $o) {
            $autor = trim(($o->autor_nombre ?? '').' '.($o->autor_apellido ?? ''));

            $items[] = [
                'rowid' => (int) $o->rowid,
                'nombre' => $o->nombre,
                'descripcion' => $o->descripcion ?? '',
                'color_tema' => $o->color_tema ?: '#6d28d9',
                'icono' => $o->icono ?: 'fa-chart-line',
                'es_publico' => (int) $o->es_publico,
                'n_widgets' => (int) $o->n_widgets,
                'date_creation' => $o->date_creation,
                'tms' => $o->tms,
                'propia' => (int) $o->fk_user_creat === $userId,
                'autor' => $autor !== '' ? $autor : '—',
            ];
        }

        return ['total' => count($items), 'dashboards' => $items];
    }

    public function obtenerDashboard(int $id): array
    {
        $o = DB::connection(self::CONNECTION)
            ->table('informes_dashboard')
            ->where('rowid', $id)
            ->first();

        if (! $o) {
            abort(response()->json(['error' => 'Dashboard no encontrado'], 404));
        }

        return [
            'rowid' => (int) $o->rowid,
            'nombre' => $o->nombre,
            'descripcion' => $o->descripcion ?? '',
            'color_tema' => $o->color_tema ?: '#6d28d9',
            'icono' => $o->icono ?: 'fa-chart-line',
            'es_publico' => (int) $o->es_publico,
            'fk_user_creat' => (int) $o->fk_user_creat,
            'date_creation' => $o->date_creation,
            'tms' => $o->tms,
            'tabs' => $this->tabsDelDashboard($o),
        ];
    }

    /**
     * Tabs del dashboard (informes_dashboard.tabs_json: [{"orden":0,"nombre":"Ventas"},...])
     * con la cantidad de widgets de cada una, tal como los muestra la pantalla de
     * Dashboard Dinamico de Dolibarr (badge "Ventas 5", "Clientes 4", etc.).
     */
    private function tabsDelDashboard(object $dash): array
    {
        $tabsDef = json_decode((string) ($dash->tabs_json ?? ''), true);
        if (! is_array($tabsDef)) {
            return [];
        }

        $conteos = DB::connection(self::CONNECTION)
            ->table('informes_dashboard_widget')
            ->where('fk_dashboard', $dash->rowid)
            ->select('tab_nombre')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('tab_nombre')
            ->pluck('total', 'tab_nombre');

        $tabs = [];
        foreach ($tabsDef as $t) {
            $nombre = (string) ($t['nombre'] ?? '');
            $tabs[] = [
                'orden' => (int) ($t['orden'] ?? 0),
                'nombre' => $nombre,
                'total_widgets' => (int) ($conteos[$nombre] ?? 0),
            ];
        }

        usort($tabs, fn ($a, $b) => $a['orden'] <=> $b['orden']);

        return $tabs;
    }

    public function datosDashboard(int $id, Request $request): array
    {
        $dash = DB::connection(self::CONNECTION)
            ->table('informes_dashboard')
            ->where('rowid', $id)
            ->first();

        if (! $dash) {
            abort(response()->json(['error' => 'Dashboard no encontrado'], 404));
        }

        $userId = (int) $request->input('user_id', 0);
        $esPropio = $userId > 0 && (int) $dash->fk_user_creat === $userId;
        if (! $esPropio && ! (int) $dash->es_publico) {
            abort(response()->json(['error' => 'Sin acceso a este dashboard'], 403));
        }

        $fechaDesde = $this->normalizarFecha($request->input('fecha_desde')) ?? now()->startOfMonth()->format('Y-m-d');
        $fechaHasta = $this->normalizarFecha($request->input('fecha_hasta')) ?? now()->endOfMonth()->format('Y-m-d');
        $fechaDesdeComp = $this->normalizarFecha($request->input('fecha_desde_comp'));
        $fechaHastaComp = $this->normalizarFecha($request->input('fecha_hasta_comp'));

        $limitInput = $request->input('limit');
        $limit = ($limitInput === null || $limitInput === '') ? null : (int) $limitInput;

        $widgetIds = array_values(array_filter(array_map('intval', (array) $request->input('widget_ids', []))));

        $query = DB::connection(self::CONNECTION)
            ->table('informes_dashboard_widget')
            ->where('fk_dashboard', $id);

        if (! empty($widgetIds)) {
            $query->whereIn('rowid', $widgetIds);
        }

        $widgets = $query->orderBy('tab_orden')->orderBy('posicion')->get();

        $items = [];
        foreach ($widgets as $w) {
            $sqlGenerado = (string) $w->sql_generado;

            $datos = $this->ejecutarWidgetSql($sqlGenerado, [
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
            ], $limit);

            $comparacion = null;
            if ($fechaDesdeComp !== null && $fechaHastaComp !== null) {
                $comparacion = $this->ejecutarWidgetSql($sqlGenerado, [
                    'fecha_desde' => $fechaDesdeComp,
                    'fecha_hasta' => $fechaHastaComp,
                ], $limit);
            }

            $items[] = [
                'rowid' => (int) $w->rowid,
                'tab_nombre' => $w->tab_nombre,
                'tab_orden' => (int) $w->tab_orden,
                'posicion' => (int) $w->posicion,
                'ancho' => (int) $w->ancho,
                'alto' => (int) $w->alto,
                'tipo_grafica' => $w->tipo_grafica,
                'titulo' => $w->titulo,
                'config' => json_decode($w->config_json ?: '{}', true) ?: [],
                'datos' => $datos,
                'comparacion' => $comparacion,
            ];
        }

        return [
            'dashboard' => [
                'rowid' => (int) $dash->rowid,
                'nombre' => $dash->nombre,
                'descripcion' => $dash->descripcion ?? '',
                'color_tema' => $dash->color_tema ?: '#6d28d9',
                'icono' => $dash->icono ?: 'fa-chart-line',
            ],
            'tabs' => $this->tabsDelDashboard($dash),
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'fecha_desde_comp' => $fechaDesdeComp,
            'fecha_hasta_comp' => $fechaHastaComp,
            'widgets' => $items,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Interpola {{variable}} en el SQL guardado del widget y lo ejecuta.
     *
     * @param  array  $extraVars  ['fecha_desde' => ..., 'fecha_hasta' => ...]
     * @param  int|null  $limit  null = respeta el LIMIT del SQL (o tope 5000); 0 = sin tope; >0 = reemplaza el LIMIT (tope 100000)
     */
    private function ejecutarWidgetSql(string $sqlGenerado, array $extraVars, ?int $limit): array
    {
        if (trim($sqlGenerado) === '') {
            return ['error' => 'El widget no tiene SQL configurado.'];
        }

        $sqlFinal = $this->interpolarVariables($sqlGenerado, $extraVars);

        if (! $this->esSelectSeguro($sqlFinal)) {
            return ['error' => 'SQL rechazado (solo SELECT).', 'sql' => $sqlFinal];
        }

        // getEntity('modulo') asume entidad única (=1): esta API no tiene contexto
        // multiempresa de Dolibarr (no hay $conf->entity disponible acá).
        $sqlFinal = preg_replace('/getEntity\s*\(\s*[\'"][^\'"]*[\'"][^)]*\)/i', '1', $sqlFinal);

        if ($limit === 0) {
            $sqlFinal = preg_replace('/\bLIMIT\s+\d+(\s*,\s*\d+)?\s*$/i', '', $sqlFinal);
        } elseif ($limit !== null && $limit > 0) {
            // El limit del request es un TOPE, no un reemplazo: si el widget ya trae su
            // propio LIMIT (ej. "Top 10 clientes"), se respeta el mas chico de los dos
            // para no romper el "Top N" que define el propio widget.
            $lo = min(100000, $limit);
            if (preg_match('/\bLIMIT\s+(\d+)(\s*,\s*\d+)?\s*$/i', $sqlFinal, $m)) {
                $limiteFinal = min((int) $m[1], $lo);
                $sqlFinal = preg_replace('/\bLIMIT\s+\d+(\s*,\s*\d+)?\s*$/i', "LIMIT {$limiteFinal}", $sqlFinal);
            } else {
                $sqlFinal .= " LIMIT {$lo}";
            }
        } elseif (! preg_match('/\bLIMIT\s+\d+/i', $sqlFinal)) {
            $sqlFinal .= ' LIMIT 5000';
        }

        $this->relajarOnlyFullGroupBy();

        $pdo = DB::connection(self::CONNECTION)->getPdo();

        try {
            $stmt = $pdo->query($sqlFinal);
        } catch (\Throwable $e) {
            return ['error' => 'Error SQL: '.$e->getMessage(), 'sql' => $sqlFinal];
        }

        $columnas = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columnas[] = $meta['name'] ?? '';
        }
        $columnas = array_values(array_filter($columnas, fn ($n) => $n !== ''));

        $filas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'columnas' => $columnas,
            'filas' => $filas,
            'total' => count($filas),
            'sql_final' => $sqlFinal,
        ];
    }

    /**
     * Reemplaza {{variable}} en el SQL. Mismo set de variables que
     * dashboardInterpolarVariables() del AJAX original.
     */
    private function interpolarVariables(string $sql, array $extraVars = []): string
    {
        $hoy = new \DateTime('now');

        $vars = array_merge([
            'mes_actual' => $hoy->format('m'),
            'anio_actual' => $hoy->format('Y'),
            'mes_anterior' => (clone $hoy)->modify('-1 month')->format('m'),
            'anio_mes_anterior' => (clone $hoy)->modify('-1 month')->format('Y'),
            'fecha_hoy' => $hoy->format('Y-m-d'),
            'fecha_inicio_mes' => $hoy->format('Y-m-01'),
            'fecha_fin_mes' => $hoy->format('Y-m-t'),
            'fecha_inicio_anio' => $hoy->format('Y-01-01'),
            'fecha_fin_anio' => $hoy->format('Y-12-31'),
            'fecha_hoy_menos_7d' => (clone $hoy)->modify('-7 days')->format('Y-m-d'),
            'fecha_hoy_menos_30d' => (clone $hoy)->modify('-30 days')->format('Y-m-d'),
            'fecha_hoy_menos_90d' => (clone $hoy)->modify('-90 days')->format('Y-m-d'),
        ], $extraVars);

        foreach ($vars as $k => $v) {
            $sql = str_replace('{{'.$k.'}}', (string) $v, $sql);
        }

        return $sql;
    }

    private function normalizarFecha($value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /** Guard anti-escritura: garantiza que la consulta es un SELECT puro (igual a InformeDinamicoService). */
    private function esSelectSeguro(string $sql): bool
    {
        $s = ltrim(html_entity_decode($sql, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! preg_match('/^SELECT\b/i', $s)) {
            return false;
        }

        $cleaned = rtrim($s, "; \t\n\r");
        if (preg_match('/;\s*\S/i', $cleaned)) {
            return false;
        }

        $prohibidas = [
            'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'REPLACE',
            'GRANT', 'REVOKE', 'RENAME', 'LOAD', 'CALL', 'EXEC', 'EXECUTE', 'HANDLER', 'LOCK',
            'UNLOCK', 'SHUTDOWN', 'FLUSH', 'KILL', 'PURGE', 'RESET', 'COMMIT', 'ROLLBACK',
            'SAVEPOINT', 'DO', 'OPTIMIZE', 'REPAIR', 'CHECKSUM', 'ANALYZE',
        ];
        foreach ($prohibidas as $kw) {
            if (preg_match('/\b'.preg_quote($kw, '/').'\b/i', $s)) {
                return false;
            }
        }
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $s)) {
            return false;
        }
        if (preg_match('/\bSET\s+@/i', $s)) {
            return false;
        }

        return true;
    }

    /**
     * Igual que en InformeDinamicoService: los SQL guardados (muchos con GROUP BY
     * "legacy", algunos generados con IA) corren bien bajo la conexión mysqli propia
     * de Dolibarr (sin ONLY_FULL_GROUP_BY); la conexión 'mysql' de Laravel sí lo activa
     * vía 'strict' => true. Se relaja solo para esta sesión de conexión.
     */
    private function relajarOnlyFullGroupBy(): void
    {
        DB::connection(self::CONNECTION)->statement(
            "SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'ONLY_FULL_GROUP_BY', '')"
        );
    }
}
