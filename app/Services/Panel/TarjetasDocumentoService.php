<?php

namespace App\Services\Panel;

use Illuminate\Support\Facades\DB;

/**
 * Datos de las tarjetas de documentos (cotización, orden de compra, entrega / solución) con el formato del tablero de Dolibarr:
 * clasificaciones de la leyenda (urgencia, oportunidad, problema), moras con su color de salud, fechas de despacho y entrega,
 * guía, importación, OC del cliente, valor y último seguimiento con su autor. Los llamadores ya saben qué ids mostrar y en qué
 * orden; este servicio solo los enriquece.
 */
class TarjetasDocumentoService
{
    private string $p;

    /** @var array<int, array> */
    private array $umbrales = [];

    public function __construct(private PanelDashboardService $dashboard)
    {
        $this->p = DB::getTablePrefix();
    }

    // ------------------------------------------------------------------ utilidades

    private function in(array $ids): string
    {
        return implode(',', array_map('intval', $ids)) ?: '0';
    }

    private function salud(int $card, int $dias): array
    {
        $u = $this->umbrales[$card] ??= $this->dashboard->umbralesCard($card);
        $k = $dias >= $u['mora'] ? 'mora' : ($dias >= $u['riesgoMin'] && $dias <= $u['riesgoMax'] ? 'riesgo' : 'aldia');

        return ['clave' => $k, 'nombre' => ['mora' => 'En mora', 'riesgo' => 'En riesgo', 'aldia' => 'Al día'][$k], 'color' => $u['colores'][$k]];
    }

    /** Última clasificación de cada tipo por documento: [id => [tipo => ['valor', 'color']]] (se omite «Sin problema»). */
    private function clasificaciones(string $modulo, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        $colores = [];
        foreach (['urgencia', 'oportunidad', 'problema', 'cumplimiento'] as $tipo) {
            $colores[$tipo] = $this->dashboard->coloresLeyenda($tipo);
            $t = $tipo;
            foreach (DB::select("SELECT p.fk_record id, p.prioridad_nueva valor FROM {$this->p}informessolu_tablero_prioridad p
                INNER JOIN (SELECT fk_record, MAX(date_creation) maxd FROM {$this->p}informessolu_tablero_prioridad WHERE modulo = ? AND tipo = ? AND fk_record IN ({$this->in($ids)}) GROUP BY fk_record) lt
                  ON lt.fk_record = p.fk_record AND lt.maxd = p.date_creation
                WHERE p.modulo = ? AND p.tipo = ? AND p.prioridad_nueva IS NOT NULL AND p.prioridad_nueva <> ''", [$modulo, $t, $modulo, $t]) as $r) {
                if (mb_strtolower($r->valor) !== 'sin problema') {
                    $out[(int) $r->id][$tipo] = ['valor' => $r->valor, 'color' => $colores[$tipo][$r->valor] ?? '#64748b'];
                }
            }
        }

        return $out;
    }

    /** Último seguimiento de cada documento con fecha y autor. @return array<int, array{texto: string, fecha: ?string, autor: string}> */
    private function seguimientos(string $tabla, string $fk, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach (DB::select("SELECT o.{$fk} id, o.tms, o.mensaje, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) autor
            FROM {$this->p}{$tabla} o INNER JOIN (SELECT {$fk} f, MAX(rowid) rid FROM {$this->p}{$tabla} WHERE {$fk} IN ({$this->in($ids)}) GROUP BY {$fk}) m ON m.rid = o.rowid
            LEFT JOIN {$this->p}user u ON u.rowid = o.fk_user_creat") as $r) {
            $texto = ActividadesService::decodificar($r->mensaje);
            $out[(int) $r->id] = ['texto' => mb_strimwidth($texto, 0, 140, '…'), 'fecha' => $r->tms ? substr((string) $r->tms, 0, 10) : null, 'autor' => (string) $r->autor];
        }

        return $out;
    }

    private static function dia(?string $f): ?string
    {
        return $f ? substr($f, 0, 10) : null;
    }

    // ------------------------------------------------------------------ cotizaciones

    /** @return array<int, array> por id de cotización */
    public function cotizaciones(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $clas = $this->clasificaciones('cotizacion', $ids);
        $seg = $this->seguimientos('observaciones_cotizaciones', 'fk_clientecotizacion', $ids);
        $out = [];
        foreach (DB::select("SELECT t.rowid id, COALESCE(t.fecha, t.date_creation) ingreso, t.total_bruto, t.tipo_moneda,
                DATEDIFF(CURDATE(), DATE(COALESCE(sol.fecha_solicitud, sol.date_creation, t.fecha, t.date_creation))) mora_total,
                DATEDIFF(CURDATE(), DATE(COALESCE(t.fecha, t.date_creation))) mora_estado,
                TRIM(CONCAT_WS(' ', COALESCE(ug.firstname, uc.firstname), COALESCE(ug.lastname, uc.lastname))) gestor
            FROM {$this->p}cotizaciones_clientecotizacion t
            LEFT JOIN {$this->p}solicitudes_solicitud sol ON sol.rowid = t.fk_solicitud
            LEFT JOIN {$this->p}user ug ON ug.rowid = t.fk_user_gestion LEFT JOIN {$this->p}user uc ON uc.rowid = t.fk_user_creat
            WHERE t.rowid IN ({$this->in($ids)})") as $r) {
            $id = (int) $r->id;
            $estado = max(0, (int) $r->mora_estado);
            $out[$id] = [
                'tipo' => 'cotizacion',
                'ingreso' => self::dia($r->ingreso), 'gestor' => (string) $r->gestor,
                'valor' => round((float) $r->total_bruto), 'moneda' => (int) $r->tipo_moneda === 1 ? 'US$' : '$',
                'moraTotal' => max(0, (int) $r->mora_total), 'moraEstado' => $estado,
                'salud' => $this->salud(2, $estado),
                'urgencia' => $clas[$id]['urgencia'] ?? null, 'oportunidad' => $clas[$id]['oportunidad'] ?? null,
                'seguimiento' => $seg[$id] ?? null,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ órdenes de compra y entregas

    /**
     * Datos por orden (sirven para la tarjeta de la orden y para la de entrega / solución).
     *
     * @return array<int, array> por id de orden
     */
    public function ordenes(array $ids, int $card = 3): array
    {
        if (! $ids) {
            return [];
        }
        $clas = $this->clasificaciones('orden', $ids);
        $seg = $this->seguimientos('observaciones_ordencompra', 'fk_orden', $ids);

        // Guías: la que sigue en tránsito (si hay) o la última.
        $guias = [];
        foreach (DB::select("SELECT fk_orden, transporte, guia, estado, fecha_entregado FROM {$this->p}ordencompra_guias_entregas WHERE fk_orden IN ({$this->in($ids)}) AND guia IS NOT NULL AND guia <> '' ORDER BY rowid") as $g) {
            $o = (int) $g->fk_orden;
            $pendiente = mb_strtoupper((string) $g->estado) !== 'ENTREGADO';
            if (! isset($guias[$o]) || $pendiente || ! $guias[$o]['pendiente']) {
                $guias[$o] = ['transporte' => trim((string) $g->transporte), 'guia' => trim((string) $g->guia), 'estado' => (string) $g->estado, 'pendiente' => $pendiente];
            }
        }

        $hoy = now(ActividadesService::TZ)->toDateString();
        $out = [];
        foreach (DB::select("SELECT t.rowid id, t.status, t.date_creation, COALESCE(t.fecha_entrega_cliente, oe.fecha_entrega) f_entrega, t.fechaDespacho f_despacho, t.numero_factura,
                oe.oc_cliente, oe.status_docs_pagos pagado, COALESCE(NULLIF(oe.total_productos, 0), NULLIF(t.total_bruto, 0), cc.total_bruto, 0) valor, im.ref im_ref, COALESCE(oe.tipo_moneda, t.tipo_moneda, cc.tipo_moneda) moneda,
                DATEDIFF(CURDATE(), DATE(t.date_creation)) mora_etapa,
                TRIM(CONCAT_WS(' ', COALESCE(ug.firstname, uc.firstname), COALESCE(ug.lastname, uc.lastname))) gestor
            FROM {$this->p}ordencompra_orden t
            LEFT JOIN {$this->p}ordencompra_orden_extrafields oe ON oe.fk_object = t.rowid
            LEFT JOIN {$this->p}importacion_importaciones im ON im.rowid = t.fk_importacion
            LEFT JOIN {$this->p}cotizaciones_clientecotizacion cc ON cc.rowid = t.fk_cliente_cotizacion
            LEFT JOIN {$this->p}user ug ON ug.rowid = t.fk_user_gestion LEFT JOIN {$this->p}user uc ON uc.rowid = t.fk_user_creat
            WHERE t.rowid IN ({$this->in($ids)})") as $r) {
            $id = (int) $r->id;
            $g = $guias[$id] ?? null;
            $fEntrega = self::dia($r->f_entrega);
            $fDespacho = self::dia($r->f_despacho);
            // Mora = días pasados de la fecha prometida (compromiso con el cliente / despacho del proveedor).
            $moraCliente = $fEntrega && $fEntrega < $hoy ? (int) abs(now(ActividadesService::TZ)->startOfDay()->diffInDays($fEntrega)) : 0;
            $moraProveedor = $fDespacho && $fDespacho < $hoy && $g === null ? (int) abs(now(ActividadesService::TZ)->startOfDay()->diffInDays($fDespacho)) : 0;
            $etapa = max(0, (int) $r->mora_etapa);
            $out[$id] = [
                'tipo' => $card === 4 ? 'entrega' : 'orden',
                'ingreso' => self::dia($r->date_creation), 'gestor' => (string) $r->gestor,
                'valor' => round((float) $r->valor), 'moneda' => (int) $r->moneda === 1 ? 'US$' : '$',
                'ocCliente' => trim((string) $r->oc_cliente), 'importacion' => $r->im_ref,
                'pago' => (int) $r->pagado === 1 ? 'pagado' : 'pendiente',
                'despacho' => ['fecha' => $fDespacho, 'mora' => $moraProveedor, 'color' => $this->salud($card, $moraProveedor)['color']],
                'entregaCliente' => ['fecha' => $fEntrega, 'mora' => $moraCliente, 'color' => $this->salud($card, $moraCliente)['color']],
                'moraEtapa' => $etapa, 'salud' => $this->salud($card, $etapa),
                'facturado' => $r->numero_factura ? trim((string) $r->numero_factura) : null,
                'guia' => $g ? ['transporte' => $g['transporte'], 'numero' => $g['guia'], 'estado' => $g['estado']] : null,
                'problema' => $clas[$id]['problema'] ?? null, 'cumplimiento' => $clas[$id]['cumplimiento'] ?? null,
                'urgencia' => $clas[$id]['urgencia'] ?? null,
                'seguimiento' => $seg[$id] ?? null,
            ];
        }

        return $out;
    }
}
