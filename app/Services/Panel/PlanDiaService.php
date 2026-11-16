<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\DB;

/**
 * Qué gestionar hoy. Dos usos con las mismas reglas:
 *  - señales de cada etapa del Flujo comercial (por contactar, sin movimiento, pendientes de pago, compromiso vencido…);
 *  - sugerencias de acción del modal «Mi día», con ejemplos concretos (los documentos más antiguos de cada caso).
 * Todo es calculado con reglas claras (sin IA): rápido, explicable y consistente con el tablero.
 */
class PlanDiaService
{
    private string $p;

    public function __construct(private PanelDashboardService $dashboard, private AlertasService $alertas, private ActividadesService $actividades, private TareasService $tareas)
    {
        $this->p = DB::getTablePrefix();
    }

    /** Condiciones SQL por etapa (alias de PanelDashboardService::definicionEtapa). @return array<string, string> */
    public function condiciones(string $clave): array
    {
        $p = $this->p;

        return match ($clave) {
            'solicitud' => [
                'porContactar' => 't.fecha_proximo_contacto IS NOT NULL AND DATE(t.fecha_proximo_contacto) <= CURDATE()',
                'sinSeguimiento' => "NOT EXISTS (SELECT 1 FROM {$p}observaciones_solicitudes o WHERE o.fk_solicitud = t.rowid)",
                'nuevas' => 'DATEDIFF(CURDATE(), DATE(t.date_creation)) <= 7',
            ],
            'cotizacion' => [
                'sinMovimiento' => "DATEDIFF(CURDATE(), GREATEST(DATE(COALESCE(t.fecha, t.date_creation)), COALESCE((SELECT DATE(MAX(o.tms)) FROM {$p}observaciones_cotizaciones o WHERE o.fk_clientecotizacion = t.rowid), '1970-01-01'))) > 30",
                'enviadas' => 't.status = 2',
            ],
            'orden' => [
                'pendientePago' => 'COALESCE(oe.status_docs_pagos, 0) <> 1 AND t.status IN (1,2,3)',
            ],
            default => [
                'compromisoVencido' => 'o.fecha_entrega_cliente IS NOT NULL AND o.fecha_entrega_cliente < CURDATE()',
                'enTransito' => "UPPER(t.estado) LIKE '%TRANSITO%'",
            ],
        };
    }

    private const SENALES = [
        'porContactar' => ['fa-phone', 'por contactar', 'rojo', 1],
        'sinSeguimiento' => ['fa-comment-slash', 'sin seguimiento', 'ambar', 3],
        'nuevas' => ['fa-star', 'nuevas (7 d)', 'azul', 5],
        'sinMovimiento' => ['fa-hourglass-half', 'sin movimiento +30 d', 'ambar', 2],
        'enviadas' => ['fa-paper-plane', 'enviadas', 'azul', 5],
        'pendientePago' => ['fa-credit-card', 'pendientes de pago', 'ambar', 2],
        'compromisoVencido' => ['fa-calendar-xmark', 'compromiso vencido', 'rojo', 1],
        'enTransito' => ['fa-truck-fast', 'en tránsito', 'azul', 4],
    ];

    /**
     * Señales de una etapa a partir de las sumas calculadas por el servicio del dashboard (clave → n). Solo las que tienen casos,
     * las más urgentes primero.
     *
     * @param  array<string, int>  $n
     * @return array<int, array{k: string, n: int, icono: string, texto: string, tono: string}>
     */
    public function senales(array $n): array
    {
        $out = [];
        foreach ($n as $k => $v) {
            if ($v > 0 && isset(self::SENALES[$k])) {
                [$icono, $texto, $tono, $orden] = self::SENALES[$k];
                $out[] = ['k' => $k, 'n' => (int) $v, 'icono' => $icono, 'texto' => $texto, 'tono' => $tono, 'o' => $orden];
            }
        }
        usort($out, fn ($a, $b) => $a['o'] <=> $b['o']);

        return array_map(fn ($s) => array_diff_key($s, ['o' => 1]), $out);
    }

    // ------------------------------------------------------------------ modal «Mi día»

    /**
     * @param  bool|null  $equipo  true = todo el equipo (solo administradores); null = equipo si es administrador, lo propio si no
     * @return array{items: array<int, array>, resumen: array, vacio: bool, alcance: string, esAdmin: bool}
     */
    public function plan(object $usuario, ?bool $equipo = null): array
    {
        $uid = (int) $usuario->getKey();
        $equipo = $usuario->isAdmin() && ($equipo ?? true);
        $items = [];

        // 1) Actividades vencidas (tareas y recordatorios)
        $venc = $this->alertas->vencidas($usuario, 1);
        $nVenc = $venc['total'] + $venc['silenciadas'];
        if ($nVenc > 0) {
            $items[] = ['clave' => 'vencidas', 'tono' => 'rojo', 'icono' => 'fa-bell', 'n' => $nVenc, 'prioridad' => 1,
                'titulo' => $nVenc === 1 ? 'Tienes 1 actividad vencida' : "Tienes {$nVenc} actividades vencidas",
                'detalle' => 'Ciérralas, pospónlas con una fecha realista o descártalas: quedan acumulándose.', 'boton' => 'Gestionar', 'accion' => ['tipo' => 'vencidas'], 'docs' => []];
        }

        // 2) Lo programado para hoy
        $hoy = (int) ($this->actividades->recordatorios($usuario, 'pendientes', 1)['resumen']['hoy'] ?? 0) + (int) $this->tareas->resumen($usuario)['hoy'];
        if ($hoy > 0) {
            $items[] = ['clave' => 'hoy', 'tono' => 'ambar', 'icono' => 'fa-calendar-day', 'n' => $hoy, 'prioridad' => 2,
                'titulo' => $hoy === 1 ? 'Tienes 1 actividad para hoy' : "Tienes {$hoy} actividades para hoy",
                'detalle' => 'Es lo que tú mismo programaste: tareas y próximos contactos del día.', 'boton' => 'Ver mi agenda', 'accion' => ['tipo' => 'actividades', 'filtro' => 'hoy'], 'docs' => []];
        }

        // 3) Documentos de cada etapa (solo los tuyos)
        $reglas = [
            ['solicitud', 'porContactar', 1, 'rojo', 'fa-phone', 'solicitud por contactar', 'solicitudes por contactar', 'Su próximo contacto ya llegó: un seguimiento a tiempo evita que el cliente se enfríe.', ['tipo' => 'url', 'ruta' => 'panel.solicitudes.index']],
            ['entrega', 'compromisoVencido', 2, 'rojo', 'fa-calendar-xmark', 'entrega pasó la fecha prometida', 'entregas pasaron la fecha prometida', 'Avisa al cliente y reprograma: es mejor que se entere por ti.', ['tipo' => 'etapa', 'clave' => 'entrega', 'nivel' => 'mora']],
            ['cotizacion', 'sinMovimiento', 3, 'ambar', 'fa-hourglass-half', 'cotización sin movimiento hace más de 30 días', 'cotizaciones sin movimiento hace más de 30 días', 'Retómalas: pregunta si sigue el interés o ciérralas para limpiar tu tablero.', ['tipo' => 'etapa', 'clave' => 'cotizacion', 'nivel' => 'mora']],
            ['orden', 'pendientePago', 3, 'ambar', 'fa-credit-card', 'orden espera el pago', 'órdenes esperan el pago', 'Recuerda al cliente el pago para poder avanzar con la compra.', ['tipo' => 'etapa', 'clave' => 'orden', 'nivel' => '']],
            ['solicitud', 'sinSeguimiento', 4, 'ambar', 'fa-comment-slash', 'solicitud sin ningún seguimiento', 'solicitudes sin ningún seguimiento', 'Registra el primer contacto: sin él no hay historial ni próximo paso.', ['tipo' => 'etapa', 'clave' => 'solicitud', 'nivel' => 'mora']],
        ];
        foreach ($reglas as [$clave, $k, $prio, $tono, $icono, $sing, $plu, $detalle, $accion]) {
            $def = $this->dashboard->definicion($clave);
            $cond = $this->condiciones($clave)[$k];
            $ref = $clave === 'entrega' ? 'o.ref' : 't.ref';
            $doc = $clave === 'entrega' ? 'o.rowid' : 't.rowid';
            $duenio = $equipo ? '' : " AND {$def['duenio']} = ?";
            $b = $equipo ? [] : [$uid];
            $total = DB::selectOne("SELECT COUNT(*) n, ".($def['valor'] ? "COALESCE(SUM({$def['valor']}),0)" : '0')." v FROM {$def['from']} WHERE {$def['activo']}{$duenio} AND ({$cond})", $b);
            $n = (int) $total->n;
            if ($n === 0) {
                continue;
            }
            $docs = array_map(fn ($r) => ['modulo' => $def['modulo'], 'doc' => (int) $r->doc, 'ref' => $r->ref, 'empresa' => $r->empresa ?: 'Sin empresa', 'dias' => max(0, (int) $r->dias), 'gestor' => $equipo ? trim((string) $r->gestor) : ''],
                DB::select("SELECT {$doc} doc, {$ref} ref, s.nom empresa, DATEDIFF(NOW(), {$def['refFecha']}) dias, TRIM(CONCAT_WS(' ', ug.firstname, ug.lastname)) gestor
                    FROM {$def['from']} LEFT JOIN {$this->p}societe s ON s.rowid = {$def['socio']} LEFT JOIN {$this->p}user ug ON ug.rowid = {$def['duenio']}
                    WHERE {$def['activo']}{$duenio} AND ({$cond}) ORDER BY dias DESC LIMIT 3", $b));
            $valor = $def['valor'] && (float) $total->v > 0 ? round((float) $total->v) : null;
            $items[] = ['clave' => "{$clave}:{$k}", 'tono' => $tono, 'icono' => $icono, 'n' => $n, 'prioridad' => $prio, 'valor' => $valor,
                'titulo' => $n === 1 ? "1 {$sing}" : "{$n} {$plu}", 'detalle' => $detalle, 'boton' => 'Ver', 'accion' => $accion + ($accion['tipo'] === 'url' ? ['href' => route($accion['ruta'], [], false)] : []), 'docs' => $docs];
        }

        usort($items, fn ($a, $b) => [$a['prioridad'], -$a['n']] <=> [$b['prioridad'], -$b['n']]);

        return ['items' => array_slice($items, 0, 6), 'resumen' => ['vencidas' => $nVenc, 'hoy' => $hoy], 'vacio' => ! $items, 'alcance' => $equipo ? 'equipo' : 'mios', 'esAdmin' => (bool) $usuario->isAdmin()];
    }
}
