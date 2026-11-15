<?php

namespace App\Services\Panel;

use App\Models\Configuracion;
use App\Services\Panel\SolicitudesTableroService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Closure;

/**
 * Métricas del dashboard del panel. Ventas = facturas de cliente (type 0) y notas
 * crédito (type 2) ya validadas (fk_statut >= 1), por fecha de factura (datef); las
 * notas crédito vienen con total negativo, así que SUM(total_ttc) es la venta neta.
 */
class PanelDashboardService
{
    public const PERIODOS = [
        '7' => 'Últimos 7 días',
        '30' => 'Últimos 30 días',
        '90' => 'Últimos 90 días',
        'anio' => 'Este año',
        '12m' => 'Últimos 12 meses',
    ];

    private string $p;

    public function __construct()
    {
        $this->p = DB::getTablePrefix();
    }

    public function resumen(string $periodo, ?int $vendedor): array
    {
        $periodo = array_key_exists($periodo, self::PERIODOS) ? $periodo : '30';
        $vendedor = $vendedor ?: null;

        return Cache::remember("panel-dashboard:{$periodo}:".($vendedor ?? 0), 60, function () use ($periodo, $vendedor) {
            [$desde, $hasta] = $this->rango($periodo);
            $dias = $desde->diffInDays($hasta) + 1;
            $antHasta = $desde->copy()->subDay();
            $antDesde = $antHasta->copy()->subDays($dias - 1);

            $actual = $this->totales($desde, $hasta, $vendedor);
            $previo = $this->totales($antDesde, $antHasta, $vendedor);

            return [
                'periodo' => $periodo,
                'periodoLabel' => self::PERIODOS[$periodo],
                'rango' => [$desde->toDateString(), $hasta->toDateString()],
                'rangoPrevio' => [$antDesde->toDateString(), $antHasta->toDateString()],
                'actual' => $actual,
                'previo' => $previo,
                'serie' => $this->serie($desde, $hasta, $antDesde, $vendedor),
                'meses' => $this->meses($vendedor),
                'diaSemana' => $this->diaSemana($desde, $hasta, $vendedor),
                'calendario' => $this->calendario($vendedor),
                'topProductos' => $this->topProductos($desde, $hasta, $vendedor),
                'topClientes' => $this->topClientes($desde, $hasta, $vendedor),
                'recientes' => $this->recientes($vendedor),
                'catalogo' => $this->catalogo(),
                'vendedores' => $this->vendedores(),
                'ultimaVenta' => DB::selectOne("SELECT MAX(datef) f FROM {$this->p}facture WHERE type IN (0,2) AND fk_statut >= 1")->f,
                'generado' => now()->format('H:i:s'),
            ];
        });
    }

    // ------------------------------------------------------------ etapas del tablero

    private const ETAPAS = [
        'solicitud' => ['nombre' => 'SOLICITUDES', 'card' => 1, 'serie' => 'Creadas', 'activos' => 'Activas'],
        'cotizacion' => ['nombre' => 'COTIZACIÓN / PROPUESTA', 'card' => 2, 'serie' => 'Creadas', 'activos' => 'Activas'],
        'orden' => ['nombre' => 'ÓRDENES DE COMPRA', 'card' => 3, 'serie' => 'Creadas', 'activos' => 'Activas'],
        'entrega' => ['nombre' => 'ENTREGA / SOLUCIÓN', 'card' => 4, 'serie' => 'Entregadas', 'activos' => 'En curso'],
    ];

    /** Colores de cada card del tablero (Config → Estilo de cards de Dolibarr): fondo, texto y acento. */
    public function coloresEtapas(): array
    {
        $colores = [
            1 => ['fondo' => '#1e3a8a', 'texto' => '#ffffff', 'acento' => '#3b82f6'],
            2 => ['fondo' => '#ea580c', 'texto' => '#ffffff', 'acento' => '#fb923c'],
            3 => ['fondo' => '#064e3b', 'texto' => '#ffffff', 'acento' => '#059669'],
            4 => ['fondo' => '#134e4a', 'texto' => '#ffffff', 'acento' => '#14b8a6'],
        ];
        try {
            foreach (DB::select("SELECT card_num, header_bg, header_text, select_color FROM {$this->p}informessolu_tablero_card_estilo") as $r) {
                $colores[(int) $r->card_num] = ['fondo' => $r->header_bg ?: $colores[(int) $r->card_num]['fondo'], 'texto' => $r->header_text ?: '#ffffff', 'acento' => $r->select_color ?: $colores[(int) $r->card_num]['acento']];
            }
        } catch (\Throwable) {
            // tabla de estilos ausente: valores por defecto
        }

        $propios = $this->coloresPersonalizados();
        foreach ([1, 2, 3, 4] as $n) {
            if (isset($propios[$n])) {
                $colores[$n] = array_merge($colores[$n], $propios[$n]);
            }
        }

        return $colores;
    }

    public const CONST_COLORES = 'PANEL_DASH_COLORES';

    public const COLOR_TASA = '#7c3aed';

    /** Color de la card "Tasa de aprobación" del tablero comercial. */
    public function colorTasa(): string
    {
        return $this->coloresPersonalizados()[5]['fondo'] ?? self::COLOR_TASA;
    }

    /**
     * Colores guardados por un administrador (valen para todos los usuarios), por número de card (1-4 etapas, 5 tasa).
     *
     * @return array<int, array<string, string>>
     */
    public function coloresPersonalizados(): array
    {
        $json = Configuracion::valores([self::CONST_COLORES])[self::CONST_COLORES] ?? null;
        $datos = is_string($json) ? json_decode($json, true) : null;
        $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;

        $out = [];
        foreach ((array) $datos as $n => $c) {
            if (! in_array((int) $n, [1, 2, 3, 4, 5], true) || ! is_array($c)) {
                continue;
            }
            $validos = array_filter(['fondo' => $hex($c['fondo'] ?? null), 'texto' => $hex($c['texto'] ?? null), 'acento' => $hex($c['acento'] ?? null)]);
            if ($validos) {
                $out[(int) $n] = $validos;
            }
        }

        return $out;
    }

    /** @param array<int, array<string, string>> $colores vacío = volver a los colores de fábrica / Dolibarr */
    public function guardarColoresPersonalizados(array $colores): void
    {
        $q = DB::table('const')->where('name', self::CONST_COLORES)->where('entity', 1);
        if (! $colores) {
            $q->delete();
        } elseif ($q->exists()) {
            $q->update(['value' => json_encode($colores)]);
        } else {
            DB::table('const')->insert(['name' => self::CONST_COLORES, 'entity' => 1, 'value' => json_encode($colores), 'type' => 'chaine', 'visible' => 0, 'note' => 'Colores de las cards del tablero comercial y del flujo comercial']);
        }
    }

    /**
     * Las cuatro etapas del tablero (solicitud → cotización → orden → entrega) con su serie del periodo.
     * Un administrador puede ver a todos; los demás, solo lo propio (campo de usuario según BUSCAR_POR_USUARIO_GESTIONADO).
     */
    public function etapas(object $usuario, string $periodo, bool $todos): array
    {
        $periodo = array_key_exists($periodo, self::PERIODOS) ? $periodo : '30';
        $todos = $todos && $usuario->isAdmin();
        $uid = $todos ? 0 : (int) $usuario->getKey();

        $res = Cache::remember("panel-etapas:{$periodo}:{$uid}", 60, function () use ($periodo, $uid) {
            [$desde, $hasta] = $this->rango($periodo);
            $dias = $desde->diffInDays($hasta) + 1;
            $antDesde = $desde->copy()->subDays($dias);
            $colores = $this->coloresEtapas();
            $uf = app(ActividadesService::class)->campoUsuario();
            [$ventDesde, $ventHasta] = $this->ventana($periodo);
            $umb = $this->umbrales();

            $etapas = [];
            foreach (self::ETAPAS as $clave => $meta) {
                $def = $this->definicionEtapa($clave, $uf);
                $b = [];
                $duenio = '';
                if ($uid) {
                    $duenio = " AND {$def['duenio']} = ?";
                    $b[] = $uid;
                }

                $vb = array_merge([$ventDesde->toDateString(), $ventHasta->toDateString()], $b);
                $ventanaW = "DATE({$def['creacion']}) BETWEEN ? AND ?";
                $activos = DB::selectOne("SELECT COUNT(*) n, ".($def['valor'] ? "COALESCE(SUM({$def['valor']}),0)" : '0')." v,
                        COALESCE(SUM(DATEDIFF(NOW(), {$def['refFecha']}) >= {$umb[$meta['card']]['mora']}),0) mora,
                        COALESCE(SUM(DATEDIFF(NOW(), {$def['refFecha']}) BETWEEN {$umb[$meta['card']]['riesgoMin']} AND {$umb[$meta['card']]['riesgoMax']}),0) riesgo
                    FROM {$def['from']} WHERE {$def['activo']} AND {$ventanaW}{$duenio}", $vb);

                // Reparto por estado de los documentos activos (para la dona de la etapa).
                $porEstado = $clave === 'entrega'
                    ? DB::select("SELECT COALESCE(NULLIF(t.estado,''), 'Sin estado') k, COUNT(*) n FROM {$def['from']} WHERE {$def['activo']} AND {$ventanaW}{$duenio} GROUP BY k ORDER BY n DESC", $vb)
                    : DB::select("SELECT t.status k, COUNT(*) n FROM {$def['from']} WHERE {$def['activo']} AND {$ventanaW}{$duenio} GROUP BY k ORDER BY n DESC", $vb);
                $estadosEtapa = array_map(fn ($r) => ['estado' => $clave === 'entrega' ? ucfirst(mb_strtolower((string) $r->k)) : ActividadesService::nombreEstado($def['modulo'], (int) $r->k), 'n' => (int) $r->n], $porEstado);

                // Antigüedad de los documentos activos por rangos de días (desde su fecha de referencia).
                $rangos = [[0, 15, '1-15 d'], [16, 30, '16-30 d'], [31, 60, '31-60 d'], [61, 90, '61-90 d'], [91, 180, '91-180 d'], [181, null, '+180 d']];
                $diasSql = "DATEDIFF(NOW(), {$def['refFecha']})";
                $cols = implode(', ', array_map(fn ($r, $i) => "COALESCE(SUM($diasSql >= {$r[0]}".($r[1] !== null ? " AND $diasSql <= {$r[1]}" : '').") ,0) r$i", $rangos, array_keys($rangos)));
                $fila = DB::selectOne("SELECT $cols FROM {$def['from']} WHERE {$def['activo']} AND {$ventanaW}{$duenio}", $vb);
                $antiguedad = array_map(fn ($r, $i) => ['etq' => $r[2], 'min' => $r[0], 'max' => $r[1], 'n' => (int) ($fila->{"r$i"} ?? 0)], $rangos, array_keys($rangos));

                // Resumen por nivel de mora (con la más antigua y la más nueva de cada uno) para la tarjeta de la pestaña.
                // Las solicitudes usan, en cambio, los grupos del tablero.
                $resumen = null;
                if ($clave === 'solicitud') {
                    $resumen = app(SolicitudesTableroService::class)->conteos($uid ?: null);
                } else {
                    $u = $umb[$meta['card']];
                    $dias = "DATEDIFF(NOW(), {$def['refFecha']})";
                    $refCol = $clave === 'entrega' ? 'o.ref' : 't.ref';
                    $docCol = $clave === 'entrega' ? 'o.rowid' : 't.rowid';
                    $niveles = [
                        'mora' => ['En mora', "$dias >= ".(int) $u['mora']],
                        'riesgo' => ['En riesgo', "$dias BETWEEN ".(int) $u['riesgoMin'].' AND '.(int) $u['riesgoMax']],
                        'aldia' => ['Al día', "$dias < ".(int) $u['mora']." AND $dias NOT BETWEEN ".(int) $u['riesgoMin'].' AND '.(int) $u['riesgoMax']],
                    ];
                    $resumen = [];
                    foreach ($niveles as $k => [$titulo, $cond]) {
                        $n = $k === 'aldia' ? max(0, (int) $activos->n - (int) $activos->mora - (int) $activos->riesgo) : (int) $activos->{$k};
                        $extremo = fn (string $orden) => ($f = DB::selectOne("SELECT {$docCol} id, {$refCol} ref, {$def['refFecha']} fecha, {$dias} dias FROM {$def['from']} WHERE {$def['activo']} AND {$ventanaW}{$duenio} AND {$cond} ORDER BY {$def['refFecha']} {$orden}, {$docCol} {$orden} LIMIT 1", $vb))
                            ? ['id' => (int) $f->id, 'ref' => $f->ref, 'fecha' => substr((string) $f->fecha, 0, 10), 'dias' => max(0, (int) $f->dias)] : null;
                        $resumen[] = ['clave' => $k, 'titulo' => $titulo, 'color' => $u['colores'][$k], 'n' => $n, 'masAntigua' => $n ? $extremo('ASC') : null, 'masNueva' => $n ? $extremo('DESC') : null];
                    }
                }

                $consulta = function (Carbon $a, Carbon $z, string $fmt) use ($def, $duenio, $uid) {
                    $bb = [$a->toDateString(), $z->toDateString()];
                    if ($uid) {
                        $bb[] = $uid;
                    }

                    return collect(DB::select("SELECT DATE_FORMAT({$def['fecha']},'{$fmt}') k, COUNT(*) n, ".($def['valor'] ? "COALESCE(SUM({$def['valor']}),0)" : '0')." v
                        FROM {$def['from']} WHERE DATE({$def['fecha']}) BETWEEN ? AND ? AND {$def['serie']}{$duenio} GROUP BY k", $bb))->keyBy('k');
                };
                $serie = $this->serieEtapa($desde, $hasta, $antDesde, $consulta);

                $etapas[] = [
                    'clave' => $clave,
                    'nombre' => $meta['nombre'],
                    'etiquetaSerie' => $meta['serie'],
                    'etiquetaActivos' => $meta['activos'],
                    'colores' => $colores[$meta['card']],
                    'activos' => (int) $activos->n,
                    'estados' => $estadosEtapa,
                    'antiguedad' => $antiguedad,
                    'resumen' => $resumen,
                    'mora' => ['mora' => (int) $activos->mora, 'riesgo' => (int) $activos->riesgo, 'aldia' => max(0, (int) $activos->n - (int) $activos->mora - (int) $activos->riesgo), 'umbral' => $umb[$meta['card']]['mora'], 'colores' => $umb[$meta['card']]['colores']],
                    'valorActivo' => $def['valor'] ? round((float) $activos->v) : null,
                    'total' => array_sum($serie['cantidades']),
                    'totalPrevio' => array_sum($serie['previosCantidades']),
                    'monto' => $def['valor'] ? array_sum($serie['montos']) : null,
                    'montoPrevio' => $def['valor'] ? array_sum($serie['previosMontos']) : null,
                    'serie' => $serie,
                ];
            }

            return [
                'periodo' => $periodo,
                'periodoLabel' => self::PERIODOS[$periodo],
                'alcance' => $uid ? 'mios' : 'todos',
                'etapas' => $etapas,
                'tasa' => $this->tasaAprobacion($uid, $ventDesde, $ventHasta, $uf),
                'desdeAnio' => $this->anioDesde(),
                'ventana' => [$ventDesde->toDateString(), $ventHasta->toDateString()],
                'generado' => now()->format('H:i:s'),
            ];
        });

        // Los colores se aplican fuera de la caché para que un cambio del administrador se vea de inmediato.
        $colores = $this->coloresEtapas();
        foreach (array_values(self::ETAPAS) as $i => $meta) {
            $res['etapas'][$i]['colores'] = $colores[$meta['card']];
        }
        $res['colorTasa'] = $this->colorTasa();

        return $res;
    }

    public function definicionEtapa(string $clave, string $uf): array
    {
        $p = $this->p;
        $cotValor = 'CASE WHEN COALESCE(t.tipo_moneda,0)=1 AND COALESCE(t.trm,0)>0 THEN COALESCE(t.total_bruto,0) * t.trm ELSE COALESCE(t.total_bruto,0) END';
        $ocValor = 'CASE WHEN COALESCE(cc.tipo_moneda,0)=1 AND COALESCE(cc.trm,0)>0 THEN COALESCE(oe.total_productos, t.total_bruto, 0) * cc.trm ELSE COALESCE(oe.total_productos, t.total_bruto, 0) END';

        return match ($clave) {
            'solicitud' => ['from' => "{$p}solicitudes_solicitud t", 'fecha' => 't.date_creation', 'creacion' => 't.date_creation', 'serie' => 't.status > 0', 'activo' => 't.status BETWEEN 1 AND 6',
                'valor' => null, 'duenio' => "t.{$uf}", 'refFecha' => 'COALESCE(t.fecha_reapertura, t.fecha_solicitud, t.date_creation)', 'modulo' => 'solicitud', 'socio' => 't.fk_soc'],
            'cotizacion' => ['from' => "{$p}cotizaciones_clientecotizacion t LEFT JOIN {$p}solicitudes_solicitud sol ON sol.rowid = t.fk_solicitud", 'fecha' => 't.date_creation', 'creacion' => 't.date_creation', 'serie' => 't.status > 0',
                'activo' => 't.status IN (1,2)', 'valor' => $cotValor, 'duenio' => "t.{$uf}", 'refFecha' => 'COALESCE(sol.fecha_solicitud, sol.date_creation, t.date_creation)', 'modulo' => 'cotizacion', 'socio' => 't.fk_soc'],
            'orden' => ['from' => "{$p}ordencompra_orden t LEFT JOIN {$p}ordencompra_orden_extrafields oe ON oe.fk_object = t.rowid LEFT JOIN {$p}cotizaciones_clientecotizacion cc ON cc.rowid = t.fk_cliente_cotizacion",
                'fecha' => 't.date_creation', 'creacion' => 't.date_creation', 'serie' => 't.status NOT IN (0,31)', 'activo' => 't.status NOT IN (0,5,9,10,31)', 'valor' => $ocValor, 'duenio' => "t.{$uf}",
                'refFecha' => 't.date_creation', 'modulo' => 'orden', 'socio' => 't.fk_soc'],
            default => ['from' => "{$p}ordencompra_guias_entregas t INNER JOIN {$p}ordencompra_orden o ON o.rowid = t.fk_orden", 'fecha' => 't.fecha_entregado', 'creacion' => 'o.date_creation',
                'serie' => "t.estado = 'ENTREGADO' AND t.fecha_entregado IS NOT NULL", 'activo' => "t.guia IS NOT NULL AND t.guia <> '' AND (t.estado IS NULL OR t.estado <> 'ENTREGADO')", 'valor' => null,
                'duenio' => "o.{$uf}", 'refFecha' => 'o.date_creation', 'modulo' => 'orden', 'socio' => 'o.fk_soc'],
        };
    }

    /** Ventana de "estado actual": el periodo elegido, sin pasar antes del año desde el que el tablero considera documentos. */
    private function ventana(string $periodo): array
    {
        [$desde, $hasta] = $this->rango($periodo);
        $piso = Carbon::create($this->anioDesde(), 1, 1)->startOfDay();

        return [$desde->lt($piso) ? $piso : $desde, $hasta];
    }

    public function anioDesde(): int
    {
        return max(2000, (int) (\App\Models\Configuracion::valores(['INFORMESSOLU_ANIO_DESDE'])['INFORMESSOLU_ANIO_DESDE'] ?? 2026));
    }

    /**
     * Umbrales de mora por card (Config → Leyenda → Estado): mora desde `mora` días, riesgo entre `riesgoMin` y `riesgoMax`.
     *
     * @return array<int, array{mora: int, riesgoMin: int, riesgoMax: int, colores: array{aldia: string, riesgo: string, mora: string}}>
     */
    private function umbrales(): array
    {
        $out = [];
        for ($n = 1; $n <= 4; $n++) {
            $out[$n] = ['mora' => 3, 'riesgoMin' => 1, 'riesgoMax' => 2, 'colores' => ['aldia' => '#16a34a', 'riesgo' => '#ea580c', 'mora' => '#dc2626']];
        }
        try {
            foreach (DB::select("SELECT card_num, item_label, color, dias_min FROM {$this->p}informessolu_tablero_leyenda WHERE categoria = 'estado' AND activo = 1 AND card_num IS NOT NULL") as $r) {
                $n = (int) $r->card_num;
                if (! isset($out[$n])) {
                    continue;
                }
                $l = mb_strtolower((string) $r->item_label);
                $clave = str_contains($l, 'mora') ? 'mora' : (str_contains($l, 'riesgo') ? 'riesgo' : 'aldia');
                if ($r->color) {
                    $out[$n]['colores'][$clave] = $r->color;
                }
                if ($clave === 'mora' && (int) $r->dias_min > 0) {
                    $out[$n]['mora'] = max($out[$n]['mora'] === 3 ? 0 : $out[$n]['mora'], (int) $r->dias_min);
                }
            }
        } catch (\Throwable) {
            // sin leyenda: umbrales por defecto
        }
        foreach ($out as $n => $u) {
            $out[$n]['riesgoMin'] = max(1, intdiv($u['mora'], 2));
            $out[$n]['riesgoMax'] = max(1, $u['mora'] - 1);
        }

        return $out;
    }

    private function nivelMora(int $dias, array $u): string
    {
        return $dias >= $u['mora'] ? 'mora' : ($dias >= $u['riesgoMin'] && $dias <= $u['riesgoMax'] ? 'riesgo' : 'aldia');
    }

    /** Serie por día (o por mes si el rango pasa de ~3 meses) con el periodo previo alineado, de cantidad y monto. */
    private function serieEtapa(Carbon $desde, Carbon $hasta, Carbon $antDesde, Closure $consulta): array
    {
        $dias = $desde->diffInDays($hasta) + 1;
        $porMes = $dias > 93;
        $fmt = $porMes ? '%Y-%m' : '%Y-%m-%d';

        $act = $consulta($desde, $hasta, $fmt);
        $prev = $consulta($antDesde, $desde->copy()->subDay(), $fmt);

        $o = ['granularidad' => $porMes ? 'mes' : 'dia', 'etiquetas' => [], 'cantidades' => [], 'montos' => [], 'previosCantidades' => [], 'previosMontos' => []];
        if ($porMes) {
            $meses = max(1, (int) round($dias / 30.4));
            for ($c = $desde->copy()->startOfMonth(); $c <= $hasta; $c->addMonth()) {
                $k = $c->format('Y-m');
                $kp = $c->copy()->subMonths($meses)->format('Y-m');
                $o['etiquetas'][] = $k;
                $o['cantidades'][] = (int) ($act[$k]->n ?? 0);
                $o['montos'][] = round((float) ($act[$k]->v ?? 0));
                $o['previosCantidades'][] = (int) ($prev[$kp]->n ?? 0);
                $o['previosMontos'][] = round((float) ($prev[$kp]->v ?? 0));
            }
        } else {
            for ($i = 0; $i < $dias; $i++) {
                $k = $desde->copy()->addDays($i)->toDateString();
                $kp = $antDesde->copy()->addDays($i)->toDateString();
                $o['etiquetas'][] = $k;
                $o['cantidades'][] = (int) ($act[$k]->n ?? 0);
                $o['montos'][] = round((float) ($act[$k]->v ?? 0));
                $o['previosCantidades'][] = (int) ($prev[$kp]->n ?? 0);
                $o['previosMontos'][] = round((float) ($prev[$kp]->v ?? 0));
            }
        }

        return $o;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango(string $periodo): array
    {
        $hasta = Carbon::today();

        return match ($periodo) {
            '7' => [$hasta->copy()->subDays(6), $hasta],
            '90' => [$hasta->copy()->subDays(89), $hasta],
            'anio' => [$hasta->copy()->startOfYear(), $hasta],
            '12m' => [$hasta->copy()->subMonths(11)->startOfMonth(), $hasta],
            default => [$hasta->copy()->subDays(29), $hasta],
        };
    }

    /** Cláusula base de ventas sobre la tabla facture con alias f. */
    private function base(?int $vendedor, array &$b): string
    {
        $sql = 'f.type IN (0,2) AND f.fk_statut >= 1';
        if ($vendedor) {
            $sql .= ' AND f.fk_user_author = ?';
            $b[] = $vendedor;
        }

        return $sql;
    }

    private function totales(Carbon $desde, Carbon $hasta, ?int $vendedor): array
    {
        $p = $this->p;
        $b = [$desde->toDateString(), $hasta->toDateString()];
        $w = $this->base($vendedor, $b);

        $f = DB::selectOne("SELECT
                COALESCE(SUM(f.total_ttc),0) neto,
                COALESCE(SUM(CASE WHEN f.type=0 THEN f.total_ttc END),0) bruto,
                COALESCE(SUM(CASE WHEN f.type=2 THEN f.total_ttc END),0) notas,
                COALESCE(SUM(CASE WHEN f.type=0 THEN 1 ELSE 0 END),0) facturas,
                COALESCE(SUM(CASE WHEN f.type=2 THEN 1 ELSE 0 END),0) n_notas,
                COUNT(DISTINCT f.fk_soc) clientes
            FROM {$p}facture f WHERE f.datef BETWEEN ? AND ? AND {$w}", $b);

        $u = DB::selectOne("SELECT COALESCE(SUM(d.qty),0) unidades
            FROM {$p}facturedet d INNER JOIN {$p}facture f ON f.rowid = d.fk_facture
            WHERE f.type = 0 AND f.datef BETWEEN ? AND ? AND {$w}", $b);

        $nuevos = DB::selectOne("SELECT COUNT(*) n FROM {$p}societe
            WHERE client IN (1,3) AND DATE(datec) BETWEEN ? AND ?", [$desde->toDateString(), $hasta->toDateString()]);

        $facturas = (int) $f->facturas;

        return [
            'neto' => (float) $f->neto,
            'bruto' => (float) $f->bruto,
            'notas' => (float) $f->notas,
            'facturas' => $facturas,
            'nNotas' => (int) $f->n_notas,
            'clientes' => (int) $f->clientes,
            'ticket' => $facturas ? (float) $f->bruto / $facturas : 0.0,
            'unidades' => (float) $u->unidades,
            'nuevosClientes' => (int) $nuevos->n,
        ];
    }

    /** Ventas por día (o por mes si el rango pasa de ~3 meses) con el periodo previo alineado. */
    private function serie(Carbon $desde, Carbon $hasta, Carbon $antDesde, ?int $vendedor): array
    {
        $p = $this->p;
        $dias = $desde->diffInDays($hasta) + 1;
        $porMes = $dias > 93;
        $fmt = $porMes ? '%Y-%m' : '%Y-%m-%d';

        $consulta = function (Carbon $a, Carbon $z) use ($p, $fmt, $vendedor) {
            $b = [$a->toDateString(), $z->toDateString()];
            $w = $this->base($vendedor, $b);

            return collect(DB::select("SELECT DATE_FORMAT(f.datef,'{$fmt}') k, SUM(f.total_ttc) v, SUM(f.type=0) n
                FROM {$p}facture f WHERE f.datef BETWEEN ? AND ? AND {$w} GROUP BY k", $b))->keyBy('k');
        };

        $act = $consulta($desde, $hasta);
        $prev = $consulta($antDesde, $desde->copy()->subDay());

        $etiquetas = $valores = $previos = $conteos = [];
        if ($porMes) {
            $cursor = $desde->copy()->startOfMonth();
            while ($cursor <= $hasta) {
                $k = $cursor->format('Y-m');
                $kp = $cursor->copy()->subMonths(max(1, (int) round($dias / 30.4)))->format('Y-m');
                $etiquetas[] = $k;
                $valores[] = round((float) ($act[$k]->v ?? 0));
                $conteos[] = (int) ($act[$k]->n ?? 0);
                $previos[] = round((float) ($prev[$kp]->v ?? 0));
                $cursor->addMonth();
            }
        } else {
            for ($i = 0; $i < $dias; $i++) {
                $d = $desde->copy()->addDays($i);
                $dp = $antDesde->copy()->addDays($i);
                $etiquetas[] = $d->toDateString();
                $valores[] = round((float) ($act[$d->toDateString()]->v ?? 0));
                $conteos[] = (int) ($act[$d->toDateString()]->n ?? 0);
                $previos[] = round((float) ($prev[$dp->toDateString()]->v ?? 0));
            }
        }

        return ['granularidad' => $porMes ? 'mes' : 'dia', 'etiquetas' => $etiquetas, 'valores' => $valores, 'previos' => $previos, 'facturas' => $conteos];
    }

    private function meses(?int $vendedor): array
    {
        $p = $this->p;
        $desde = Carbon::today()->subMonths(11)->startOfMonth();
        $b = [$desde->toDateString(), Carbon::today()->toDateString()];
        $w = $this->base($vendedor, $b);
        $rows = collect(DB::select("SELECT DATE_FORMAT(f.datef,'%Y-%m') k, SUM(f.total_ttc) v, SUM(f.type=0) n
            FROM {$p}facture f WHERE f.datef BETWEEN ? AND ? AND {$w} GROUP BY k", $b))->keyBy('k');

        $out = [];
        for ($c = $desde->copy(); $c <= Carbon::today(); $c->addMonth()) {
            $k = $c->format('Y-m');
            $out[] = ['mes' => $k, 'valor' => round((float) ($rows[$k]->v ?? 0)), 'facturas' => (int) ($rows[$k]->n ?? 0)];
        }

        return $out;
    }

    /** Ventas por día de la semana (lunes = 0) dentro del periodo. */
    private function diaSemana(Carbon $desde, Carbon $hasta, ?int $vendedor): array
    {
        $p = $this->p;
        $b = [$desde->toDateString(), $hasta->toDateString()];
        $w = $this->base($vendedor, $b);
        $rows = collect(DB::select("SELECT WEEKDAY(f.datef) d, SUM(f.total_ttc) v, SUM(f.type=0) n
            FROM {$p}facture f WHERE f.datef BETWEEN ? AND ? AND {$w} GROUP BY d", $b))->keyBy('d');

        return collect(range(0, 6))->map(fn ($d) => [
            'valor' => round((float) ($rows[$d]->v ?? 0)),
            'facturas' => (int) ($rows[$d]->n ?? 0),
        ])->all();
    }

    /** Calendario de calor: ventas diarias de las últimas 26 semanas (semanas completas, lunes a domingo). */
    private function calendario(?int $vendedor): array
    {
        $p = $this->p;
        $fin = Carbon::today()->endOfWeek(Carbon::SUNDAY);
        $ini = $fin->copy()->subWeeks(26)->addDay()->startOfWeek(Carbon::MONDAY);
        $b = [$ini->toDateString(), Carbon::today()->toDateString()];
        $w = $this->base($vendedor, $b);
        $rows = collect(DB::select("SELECT f.datef k, SUM(f.total_ttc) v FROM {$p}facture f
            WHERE f.datef BETWEEN ? AND ? AND {$w} GROUP BY f.datef", $b))->keyBy('k');

        $dias = [];
        for ($d = $ini->copy(); $d <= $fin; $d->addDay()) {
            $k = $d->toDateString();
            $dias[] = ['f' => $k, 'v' => round((float) ($rows[$k]->v ?? 0)), 'futuro' => $d->isFuture()];
        }

        return $dias;
    }

    private function topProductos(Carbon $desde, Carbon $hasta, ?int $vendedor): array
    {
        $p = $this->p;
        $b = [$desde->toDateString(), $hasta->toDateString()];
        $w = $this->base($vendedor, $b);

        return collect(DB::select("SELECT d.fk_product id, COALESCE(NULLIF(pr.label,''), d.description) nombre, pr.ref,
                SUM(d.total_ttc) valor, SUM(d.qty) unidades
            FROM {$p}facturedet d
            INNER JOIN {$p}facture f ON f.rowid = d.fk_facture
            LEFT JOIN {$p}product pr ON pr.rowid = d.fk_product
            WHERE f.type = 0 AND f.datef BETWEEN ? AND ? AND {$w}
            GROUP BY d.fk_product, nombre, pr.ref ORDER BY valor DESC LIMIT 8", $b))
            ->map(fn ($r) => ['nombre' => mb_strimwidth(trim((string) $r->nombre) ?: 'Sin nombre', 0, 60, '…'), 'ref' => $r->ref, 'valor' => round((float) $r->valor), 'unidades' => (float) $r->unidades])
            ->all();
    }

    private function topClientes(Carbon $desde, Carbon $hasta, ?int $vendedor): array
    {
        $p = $this->p;
        $b = [$desde->toDateString(), $hasta->toDateString()];
        $w = $this->base($vendedor, $b);

        return collect(DB::select("SELECT s.rowid id, s.nom nombre, SUM(f.total_ttc) valor, SUM(f.type=0) facturas
            FROM {$p}facture f INNER JOIN {$p}societe s ON s.rowid = f.fk_soc
            WHERE f.datef BETWEEN ? AND ? AND {$w}
            GROUP BY s.rowid, s.nom ORDER BY valor DESC LIMIT 8", $b))
            ->map(fn ($r) => ['id' => (int) $r->id, 'nombre' => mb_strimwidth((string) $r->nombre, 0, 50, '…'), 'valor' => round((float) $r->valor), 'facturas' => (int) $r->facturas])
            ->all();
    }

    private function recientes(?int $vendedor): array
    {
        $p = $this->p;
        $b = [];
        $w = $this->base($vendedor, $b);

        return collect(DB::select("SELECT f.rowid id, f.ref, f.type, f.datef fecha, f.total_ttc total, s.nom cliente,
                TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) vendedor
            FROM {$p}facture f
            LEFT JOIN {$p}societe s ON s.rowid = f.fk_soc
            LEFT JOIN {$p}user u ON u.rowid = f.fk_user_author
            WHERE {$w} ORDER BY f.datef DESC, f.rowid DESC LIMIT 8", $b))
            ->map(fn ($r) => ['id' => (int) $r->id, 'ref' => $r->ref, 'nota' => (int) $r->type === 2, 'fecha' => $r->fecha, 'total' => (float) $r->total, 'cliente' => mb_strimwidth((string) $r->cliente, 0, 40, '…'), 'vendedor' => $r->vendedor])
            ->all();
    }

    private function catalogo(): array
    {
        $p = $this->p;
        $r = DB::selectOne("SELECT
            (SELECT COUNT(*) FROM {$p}product WHERE tosell = 1) productos,
            (SELECT COUNT(*) FROM {$p}societe WHERE client IN (1,3)) clientes,
            (SELECT COUNT(*) FROM {$p}user WHERE statut = 1) usuarios,
            (SELECT COUNT(*) FROM {$p}facture WHERE fk_statut = 0) borradores");

        return ['productos' => (int) $r->productos, 'clientes' => (int) $r->clientes, 'usuarios' => (int) $r->usuarios, 'borradores' => (int) $r->borradores];
    }

    /** Vendedores con ventas en los últimos 12 meses (para el filtro). */
    private function vendedores(): array
    {
        $p = $this->p;

        return collect(DB::select("SELECT u.rowid id, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) nombre, SUM(f.total_ttc) v
            FROM {$p}facture f INNER JOIN {$p}user u ON u.rowid = f.fk_user_author
            WHERE f.type IN (0,2) AND f.fk_statut >= 1 AND f.datef >= ?
            GROUP BY u.rowid, nombre ORDER BY v DESC LIMIT 30", [Carbon::today()->subMonths(11)->startOfMonth()->toDateString()]))
            ->map(fn ($r) => ['id' => (int) $r->id, 'nombre' => $r->nombre])
            ->all();
    }

    // ------------------------------------------------------------ KPIs, documentos y leyenda del tablero

    /** Cotizaciones del periodo que se volvieron orden de compra (misma fórmula del tablero). */
    private function tasaAprobacion(int $uid, Carbon $a, Carbon $z, string $uf): array
    {
        $p = $this->p;
        $b = [$a->toDateString(), $z->toDateString()];
        $d = '';
        if ($uid) {
            $d = " AND t.{$uf} = ?";
            $b[] = $uid;
        }
        $apr = DB::selectOne("SELECT COUNT(DISTINCT t.fk_cliente_cotizacion) n FROM {$p}ordencompra_orden t WHERE t.status NOT IN (0,31) AND t.fk_cliente_cotizacion > 0 AND DATE(t.date_creation) BETWEEN ? AND ?{$d}", $b);
        $tot = DB::selectOne("SELECT COUNT(*) n FROM {$p}cotizaciones_clientecotizacion t WHERE t.status > 0 AND DATE(t.date_creation) BETWEEN ? AND ?{$d}", $b);

        return ['aprobadas' => (int) $apr->n, 'total' => (int) $tot->n];
    }

    /** Colores de la leyenda: categoria => etiqueta => color. */
    private function leyenda(): array
    {
        $out = [];
        try {
            foreach (DB::select("SELECT categoria, cat_label, cat_sub, item_label, color FROM {$this->p}informessolu_tablero_leyenda WHERE activo = 1 ORDER BY categoria, card_num, orden, rowid") as $r) {
                $out[$r->categoria]['nombre'] = $r->cat_label;
                $out[$r->categoria]['sub'] = $r->cat_sub;
                $out[$r->categoria]['items'][$r->item_label] = $r->color;
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    private function sqlUltimaPrioridad(string $tipo): string
    {
        $p = $this->p;
        $t = str_replace("'", '', $tipo);

        return "SELECT p.modulo, p.fk_record, p.prioridad_nueva FROM {$p}informessolu_tablero_prioridad p
            INNER JOIN (SELECT modulo, fk_record, MAX(date_creation) maxd FROM {$p}informessolu_tablero_prioridad WHERE tipo = '{$t}' GROUP BY modulo, fk_record) lt
              ON lt.modulo = p.modulo AND lt.fk_record = p.fk_record AND lt.maxd = p.date_creation
            WHERE p.tipo = '{$t}' AND p.prioridad_nueva IS NOT NULL AND p.prioridad_nueva <> ''";
    }

    /**
     * Métricas de la leyenda del tablero (urgencia, oportunidad, problemas, estado operativo, cumplimiento) con sus colores.
     */
    public function metricasLeyenda(object $usuario, string $periodo, bool $todos): array
    {
        $periodo = array_key_exists($periodo, self::PERIODOS) ? $periodo : '30';
        $uid = ($todos && $usuario->isAdmin()) ? 0 : (int) $usuario->getKey();

        return Cache::remember("panel-leyenda:{$periodo}:{$uid}", 60, function () use ($periodo, $uid) {
            $p = $this->p;
            $uf = app(ActividadesService::class)->campoUsuario();
            [$a, $z] = $this->ventana($periodo);
            $rango = [$a->toDateString(), $z->toDateString()];
            $dueno = fn (string $col) => $uid ? " AND {$col} = {$uid}" : '';
            $v = fn (string $fecha) => "DATE({$fecha}) BETWEEN '{$rango[0]}' AND '{$rango[1]}'";

            $cuenta = fn (string $sql) => collect(DB::select($sql))->mapWithKeys(fn ($r) => [(string) $r->valor => (int) $r->c])->all();

            $cumpl = $cuenta("SELECT pr.prioridad_nueva valor, COUNT(DISTINCT o.rowid) c
                FROM {$p}ordencompra_guias_entregas ge INNER JOIN {$p}ordencompra_orden o ON o.rowid = ge.fk_orden
                INNER JOIN (".$this->sqlUltimaPrioridad('cumplimiento').") pr ON pr.modulo = 'orden' AND pr.fk_record = o.rowid
                WHERE ge.guia IS NOT NULL AND ge.guia <> '' AND (ge.estado IS NULL OR ge.estado <> 'ENTREGADO') AND {$v('o.date_creation')}".$dueno("o.{$uf}").' GROUP BY pr.prioridad_nueva');
            $prob = $cuenta("SELECT pr.prioridad_nueva valor, COUNT(*) c FROM (".$this->sqlUltimaPrioridad('problema').") pr
                INNER JOIN {$p}ordencompra_orden t ON t.rowid = pr.fk_record AND pr.modulo = 'orden'
                WHERE t.status NOT IN (0,5,9,10,31) AND {$v('t.date_creation')}".$dueno("t.{$uf}").' GROUP BY pr.prioridad_nueva');
            $opo = $cuenta("SELECT pr.prioridad_nueva valor, COUNT(*) c FROM (".$this->sqlUltimaPrioridad('oportunidad').") pr
                INNER JOIN {$p}cotizaciones_clientecotizacion t ON t.rowid = pr.fk_record AND pr.modulo = 'cotizacion'
                WHERE t.status IN (1,2) AND {$v('t.date_creation')}".$dueno("t.{$uf}").' GROUP BY pr.prioridad_nueva');

            $urg = [];
            foreach ([['solicitudes_solicitud', 'solicitud', 't.status BETWEEN 1 AND 6'], ['cotizaciones_clientecotizacion', 'cotizacion', 't.status IN (1,2)'], ['ordencompra_orden', 'orden', 't.status NOT IN (0,5,9,10,31)']] as [$tabla, $modulo, $act]) {
                foreach (DB::select("SELECT pr.prioridad_nueva valor, COUNT(*) c FROM (".$this->sqlUltimaPrioridad('urgencia').") pr
                    INNER JOIN {$p}{$tabla} t ON t.rowid = pr.fk_record AND pr.modulo = '{$modulo}'
                    WHERE {$act} AND {$v('t.date_creation')}".$dueno("t.{$uf}").' GROUP BY pr.prioridad_nueva') as $r) {
                    $urg[$r->valor] = ($urg[$r->valor] ?? 0) + (int) $r->c;
                }
            }

            $eo = DB::selectOne("SELECT COALESCE(SUM(COALESCE(oe.status_docs_pagos,0) = 1),0) pagado,
                    COALESCE(SUM(COALESCE(oe.status_docs_pagos,0) = 0 AND t.status IN (1,2,3)),0) pendiente,
                    COALESCE(SUM(t.status IN (32,33,34,35,36,37)),0) bloqueado
                FROM {$p}ordencompra_orden t LEFT JOIN {$p}ordencompra_orden_extrafields oe ON oe.fk_object = t.rowid
                WHERE t.status NOT IN (0,5,9,10,31) AND {$v('t.date_creation')}".$dueno("t.{$uf}"));

            return [
                'categorias' => [
                    'urgencia' => $urg, 'oportunidad' => $opo, 'problema' => $prob,
                    'estado_op' => ['Pagado' => (int) $eo->pagado, 'Pendiente pago' => (int) $eo->pendiente, 'Bloqueado' => (int) $eo->bloqueado],
                    'cumplimiento' => $cumpl,
                ],
                'leyenda' => $this->leyenda(),
                'ventana' => $rango,
            ];
        });
    }

    /**
     * Documentos activos de una etapa (como las cards del Kanban): más atrasados primero, con su nivel de mora y las
     * clasificaciones de la leyenda (urgencia, oportunidad, problema, cumplimiento).
     *
     * @return array{items: array, total: int, umbral: int}
     */
    public function documentosEtapa(object $usuario, string $clave, string $periodo, bool $todos, string $q = '', int $limite = 40, string $nivel = ''): array
    {
        abort_unless(isset(self::ETAPAS[$clave]), 422, 'Etapa inválida.');
        $periodo = array_key_exists($periodo, self::PERIODOS) ? $periodo : '30';
        $uid = ($todos && $usuario->isAdmin()) ? 0 : (int) $usuario->getKey();
        $p = $this->p;
        $uf = app(ActividadesService::class)->campoUsuario();
        $def = $this->definicionEtapa($clave, $uf);
        $umb = $this->umbrales()[self::ETAPAS[$clave]['card']];
        [$a, $z] = $this->ventana($periodo);

        $b = [$a->toDateString(), $z->toDateString()];
        $w = "{$def['activo']} AND DATE({$def['creacion']}) BETWEEN ? AND ?";
        if ($uid) {
            $w .= " AND {$def['duenio']} = ?";
            $b[] = $uid;
        }
        // Filtro por nivel de mora (En mora / En riesgo / Al día), con los mismos cortes que el resumen.
        $dias = "DATEDIFF(NOW(), {$def['refFecha']})";
        $w .= match ($nivel) {
            'mora' => " AND $dias >= ".(int) $umb['mora'],
            'riesgo' => " AND $dias BETWEEN ".(int) $umb['riesgoMin'].' AND '.(int) $umb['riesgoMax'],
            'aldia' => " AND $dias < ".(int) $umb['mora']." AND $dias NOT BETWEEN ".(int) $umb['riesgoMin'].' AND '.(int) $umb['riesgoMax'],
            default => '',
        };
        if (trim($q) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($q)).'%';
            $refCol = $clave === 'entrega' ? 'o.ref' : 't.ref';
            $w .= " AND ({$refCol} LIKE ? OR s.nom LIKE ?)";
            array_push($b, $like, $like);
        }

        $total = (int) DB::selectOne("SELECT COUNT(*) n FROM {$def['from']} LEFT JOIN {$p}societe s ON s.rowid = {$def['socio']} WHERE {$w}", $b)->n;

        $campos = match ($clave) {
            'solicitud' => "t.rowid id, t.rowid doc, t.ref, t.status, t.asunto titulo, t.total_ttc valor, t.fecha_proximo_contacto proximo",
            'cotizacion' => "t.rowid id, t.rowid doc, t.ref, t.status, t.label titulo, {$def['valor']} valor, NULL proximo",
            'orden' => "t.rowid id, t.rowid doc, t.ref, t.status, t.asunto titulo, {$def['valor']} valor, NULL proximo",
            default => "t.rowid id, o.rowid doc, o.ref, 0 status, CONCAT_WS(' · ', NULLIF(t.transporte,''), NULLIF(t.guia,''), NULLIF(t.estado,'')) titulo, NULL valor, NULL proximo",
        };
        $rows = DB::select("SELECT {$campos}, DATEDIFF(NOW(), {$def['refFecha']}) dias, {$def['refFecha']} fecha, s.nom empresa, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) gestor
            FROM {$def['from']} LEFT JOIN {$p}societe s ON s.rowid = {$def['socio']} LEFT JOIN {$p}user u ON u.rowid = {$def['duenio']}
            WHERE {$w} ORDER BY dias DESC, id DESC LIMIT {$limite}", $b);

        // Clasificaciones de la leyenda de estos documentos
        $ids = array_values(array_unique(array_map(fn ($r) => (int) $r->doc, $rows)));
        $clasif = [];
        if ($ids) {
            $leyenda = $this->leyenda();
            $in = implode(',', $ids);
            foreach (['urgencia', 'oportunidad', 'problema', 'cumplimiento'] as $tipo) {
                foreach (DB::select("SELECT pr.fk_record doc, pr.prioridad_nueva valor FROM (".$this->sqlUltimaPrioridad($tipo).") pr WHERE pr.modulo = '{$def['modulo']}' AND pr.fk_record IN ({$in})") as $r) {
                    if ($r->valor !== 'Sin problema') {
                        $clasif[(int) $r->doc][] = ['tipo' => $tipo, 'valor' => $r->valor, 'color' => $leyenda[$tipo]['items'][$r->valor] ?? '#64748b'];
                    }
                }
            }
        }

        $estados = ['solicitud' => [1 => 'Activa', 2 => 'Cot. Exterior', 3 => 'Cot. Local', 4 => 'Import', 5 => 'Enviada', 6 => 'Cot. USA'], 'cotizacion' => [1 => 'Activada', 2 => 'Enviada'],
            'orden' => [1 => 'Activa', 2 => 'Pago', 3 => 'Enviada', 6 => 'Aprob. Sol.', 7 => 'Aprobada', 15 => 'Liq. Sol.', 16 => 'Liquidada', 17 => 'Desemb. Sol.', 18 => 'Desemb. Apr.', 20 => 'Pago Confirm.', 32 => 'En revisión', 33 => 'Recolección', 34 => 'Env. Programar', 35 => 'Programado', 36 => 'Reprogramando', 37 => 'Reprogramado']];

        return [
            'total' => $total,
            'umbral' => $umb['mora'],
            'colores' => $umb['colores'],
            'items' => array_map(fn ($r) => [
                'id' => (int) $r->id, 'doc' => (int) $r->doc, 'modulo' => $def['modulo'], 'ref' => $r->ref, 'empresa' => $r->empresa ?: 'Sin empresa', 'gestor' => $r->gestor,
                'titulo' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $r->titulo)))), 0, 110, '…'), 'valor' => $r->valor ? round((float) $r->valor) : null,
                'estado' => $clave === 'entrega' ? null : ($estados[$clave][(int) $r->status] ?? null), 'dias' => max(0, (int) $r->dias), 'proximo' => $r->proximo ? substr((string) $r->proximo, 0, 10) : null, 'desde' => $r->fecha ? substr((string) $r->fecha, 0, 10) : null,
                'nivel' => $this->nivelMora(max(0, (int) $r->dias), $umb), 'clasif' => $clasif[(int) $r->doc] ?? [],
            ], $rows),
        ];
    }
}
