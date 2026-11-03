<?php

namespace App\Services\ServiceGlobal;

use App\Models\Configuracion;
use App\Models\CuentaBancaria;
use App\Models\Factura;
use App\Models\FacturaDetalle;
use App\Models\PuntoFidelizacion;
use App\Models\PuntoFidelizacionDetalle;
use App\Models\ResolucionFactura;
use App\Models\TransaccionDian;

/**
 * Funciones de negocio reutilizables entre Services (independientes de un
 * Request HTTP puntual), para evitar duplicar la misma logica en distintos
 * puntos de entrada (endpoint de detalle, respuesta de creacion de ticket, etc.).
 */
class ServiceFuncionesService
{
    private const TIPOS_FAC_VALIDOS = [1, 2, 3];

    private const CAMPO_IMPUESTO_EXTRA = [
        1 => 'ibua',
        2 => 'icl',
        3 => 'adv',
        4 => 'icui',
        5 => 'impuesto_consumo',
        6 => 'otros_impuestos',
    ];

    private const NOMBRE_IMPUESTO_EXTRA = [
        1 => 'IBUA',
        2 => 'ICL',
        3 => 'ADV',
        4 => 'ICUI',
        5 => 'Al Consumo',
        6 => 'Otros',
    ];

    /**
     * Arma el detalle completo de una factura (encabezado, resolucion/QR/CUFE,
     * metodo de pago, puntos de fidelizacion, items e impuestos) a partir de
     * su id (rowid).
     */
    public function datosFactura(int $id): array
    {
        $factura = Factura::query()
            ->select(['rowid', 'ref', 'datec', 'total_ht', 'total_tva', 'total_ttc', 'note_private', 'fk_soc'])
            ->with([
                'extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'tipo_fac', 'metodoPago', 'efectivo', 'regreso','ref_local'),
                'domicilio' => fn ($q) => $q->select('rowid', 'fk_facture', 'direccion_entrega', 'telefono_entrega', 'detalle_entrega', 'numero_bolsas', 'fk_tipo_domicilio'),
                'domicilio.tipoDomicilio' => fn ($q) => $q->select('rowid', 'tipo'),
                'sociedad' => fn ($q) => $q->select('rowid', 'nom', 'siren'),
            ])
            ->where('rowid', $id)
            ->first();

        if (!$factura) {
            abort(401, 'No existe una factura con ese ID, porfavor contacte al administrador.');
        }

        $tipoFac = (int) $factura->extrafields?->tipo_fac;
        $resolucion = ['resolucion' => '', 'prefijoNumero' => '', 'qr' => '', 'cufe' => '', 'mensajeAlerta' => ''];

        if (in_array($tipoFac, self::TIPOS_FAC_VALIDOS, true)) {
            $resolucion = $this->datosResolucion($tipoFac, (int) $factura->rowid);
        }

        $metodo = $this->metodosPago($factura->extrafields?->metodoPago, (float) $factura->total_ttc);
        $puntos = $this->datosPuntos((int) $factura->fk_soc, (int) $factura->rowid);
        $detalleItems = $this->itemsFactura($factura);
        $detalleImpuestos = $this->impuestosFactura((int) $factura->rowid);
        $configuracion = Configuracion::valores(['FOOTERLEGAL', 'FOOTERGRATITUD']);
        $domicilio = $factura->domicilio;

        return [
            'id' => (int) $factura->rowid,
            'numerFactura' => $factura->ref,
            'tipoFac' => $tipoFac,
            'consecutivo' => $factura->ref,
            'refLocal' => $factura->extrafields?->ref_local,
            'nombreCliente' => $factura->sociedad?->nom,
            'documentoCliente' => $factura->sociedad?->siren,
            'fecha' => $factura->datec,
            'cambio' => (int) ($factura->extrafields?->regreso ?? 0),
            'efectivo' => (float) ($factura->extrafields?->efectivo ?? 0),
            'totalPagado' => (int) round((float) $factura->total_ttc),
            'subTotal' => (int) round((float) $factura->total_ht),
            'totalIva' => (int) round((float) $factura->total_tva),
            'metodoPago' => $metodo,
            'otrosImpuestos' => (int) round($detalleImpuestos['totalImpuestosExtra']),
            'totalDescuento' => $detalleItems['totalDescuento'],
            'QR' => $resolucion['qr'],
            'CUFE' => $resolucion['cufe'],
            'mensajeAlerta' => $resolucion['mensajeAlerta'],
            'resolucion' => $resolucion['resolucion'],
            'puntosAcumulados' => $puntos['puntosAcumulados'],
            'puntosVenta' => $puntos['puntosVenta'],
            'puntosRedimidos' => $puntos['puntosRedimidos'],
            'puntosVencimiento' => $puntos['puntosVencimiento'],
            'fechaPuntosVencimiento' => $puntos['fechaVencimiento'],
            'footerLegal' => $configuracion['FOOTERLEGAL'] ?? '',
            'footerGratitud' => $configuracion['FOOTERGRATITUD'] ?? '',
            'footerIntegros' => 'Proveedor del software de facturación: Intégros 365 S.A.S. - Software propio',
            'domiDireccion' => $domicilio?->direccion_entrega ?? '',
            'domiTelefono' => $domicilio?->telefono_entrega ?? '',
            'domiBolsas' => (int) ($domicilio?->numero_bolsas ?? 0),
            'domiObservaciones' => $domicilio?->detalle_entrega ?? '',
            'domiTipo' => (int) ($domicilio?->fk_tipo_domicilio ?? 0),
            'domiTipoDescripcion' => $domicilio?->tipoDomicilio?->tipo ?? '',
            'notaPrivada' => $factura->note_private ?? '',
            'items' => $detalleItems['items'],
            'impuestos' => $detalleImpuestos['impuestos'],
            'facturaOriginal' => '',
        ];
    }

    /**
     * Texto de la resolucion/numeracion vigente y datos de QR/CUFE de
     * facturacion electronica, segun el tipo de documento (1=factura,
     * 2=factura POS, 3=borrador).
     */
    private function datosResolucion(int $tipoFac, int $idFactura): array
    {
        $resolucion = ResolucionFactura::activaPara($tipoFac);

        if (!$resolucion) {
            abort(401, 'Resolución no válida, favor contactar al administrador.');
        }

        $fechaDesde = explode(' ', (string) $resolucion->fecha_desde)[0];
        $fechaHasta = explode(' ', (string) $resolucion->fecha_hasta)[0];
        $rangoInicio = (int) $resolucion->de;
        $rangoFin = (int) $resolucion->a;

        $qr = '';
        $cufe = '';
        $mensajeAlerta = '';

        if ($tipoFac === 3) {
            $qr = $resolucion->info_qr ?: '';
            $cufe = $this->generarCufeAleatorio();
        } else {
            $cufeGuardado = TransaccionDian::cufePorFactura($tipoFac, $idFactura);

            if ($cufeGuardado) {
                $qr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufeGuardado;
                $cufe = $cufeGuardado;
            } else {
                $mensajeAlerta = 'No se encontro QR';
            }
        }

        $textoResolucion = "Numeración {$resolucion->resolucion} habilitada desde {$resolucion->prefijo}{$rangoInicio}     hasta {$resolucion->prefijo}{$rangoFin}";

        if ($tipoFac !== 3) {
            $textoResolucion .= " - Fecha inicial {$fechaDesde} - Fecha final {$fechaHasta}";
        }

        return [
            'resolucion' => $textoResolucion,
            'prefijoNumero' => $tipoFac === 3 ? 'ORDEN DE COMPRA #' : 'FACTURA DE VENTA #',
            'qr' => $qr,
            'cufe' => 'CUFE: '.$cufe,
            'mensajeAlerta' => $mensajeAlerta,
        ];
    }

    private function generarCufeAleatorio(int $longitud = 96): string
    {
        $caracteres = '0123456789abcdef';
        $resultado = '';

        for ($i = 0; $i < $longitud; $i++) {
            $resultado .= $caracteres[random_int(0, strlen($caracteres) - 1)];
        }

        return $resultado;
    }

    /**
     * Desglosa el JSON de metodoPago guardado en la factura, resolviendo
     * el nombre de cada cuenta bancaria usada como metodo de pago.
     */
    private function metodosPago(?string $metodoPagoJson, float $totalTtc): array
    {
        if ($metodoPagoJson === null || $metodoPagoJson === '') {
            return [['metodo' => 'Sin método', 'valor' => (int) $totalTtc]];
        }

        $decodificado = json_decode($metodoPagoJson, true);

        if (!is_array($decodificado)) {
            return [['metodo' => 'Sin método', 'valor' => (int) $totalTtc]];
        }

        $cuentas = CuentaBancaria::query()
            ->select('rowid', 'ref', 'label')
            ->orderByRaw("(ref = 'LIQ') DESC")
            ->orderBy('ref')
            ->get();

        return collect($decodificado)
            ->map(function ($linea) use ($cuentas) {
                $cuenta = $cuentas->first(fn ($c) => (string) $c->rowid === (string) ($linea['idMetodo'] ?? ''));

                return [
                    'metodo' => $cuenta->label ?? 'Método no encontrado',
                    'valor' => $linea['valor'] ?? 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Puntos de fidelizacion del cliente y los redimidos/generados por esta factura.
     */
    private function datosPuntos(int $idCliente, int $idFactura): array
    {
        $resumen = PuntoFidelizacion::query()
            ->where('fk_client', $idCliente)
            ->first(['puntos_acomulados', 'proximos_vencer', 'fecha_vencimiento']);

        $detalle = PuntoFidelizacionDetalle::query()
            ->where('fk_facture', $idFactura)
            ->first(['cantidad_puntos', 'puntos_redimidos']);

        return [
            'puntosAcumulados' => (int) ($resumen->puntos_acomulados ?? 0),
            'puntosVenta' => (int) ($detalle->cantidad_puntos ?? 0),
            'puntosRedimidos' => (int) ($detalle->puntos_redimidos ?? 0),
            'puntosVencimiento' => (int) ($resumen->proximos_vencer ?? 0),
            'fechaVencimiento' => $resumen?->fecha_vencimiento ? explode(' ', $resumen->fecha_vencimiento)[0] : date('Y-m-d'),
        ];
    }

    /**
     * Lineas de la factura con su producto, lote, codigo de barras,
     * impuesto extra aplicable y cantidad devuelta por notas credito.
     */
    private function itemsFactura(Factura $factura): array
    {
        $devoluciones = $this->cantidadesDevueltas((int) $factura->rowid);

        $prefijo = (new FacturaDetalle)->getConnection()->getTablePrefix();

        $lineas = FacturaDetalle::query()
            ->where('fk_facture', $factura->rowid)
            ->select(['rowid', 'fk_product', 'qty', 'total_tva', 'total_ttc', 'total_localtax1', 'price', 'batch'])
            ->selectRaw("
                (SELECT JSON_ARRAYAGG(JSON_OBJECT('idAlmacen', ps.fk_entrepot, 'cantidad', ps.reel))
                    FROM {$prefijo}product_stock ps WHERE ps.fk_product = {$prefijo}facturedet.fk_product) AS stock_json
            ")
            ->with([
                'producto' => fn ($q) => $q->select('rowid', 'label', 'ref', 'tobatch'),
                'producto.extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'codigo_barras'),
                'producto.impuestosExtra' => fn ($q) => $q->select('rowid', 'fk_product', 'fk_type'),
                'extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'monto_descuento', 'ibua', 'icl', 'adv', 'icui', 'impuesto_consumo', 'otros_impuestos'),
            ])
            ->get();

        $items = [];
        $totalDescuento = 0;

        foreach ($lineas as $linea) {
            $producto = $linea->producto;
            $extra = $linea->extrafields;
            $impuestoExtra = $producto?->impuestosExtra->first();
            $campoImpuesto = $impuestoExtra ? self::CAMPO_IMPUESTO_EXTRA[$impuestoExtra->fk_type] ?? null : null;
            $valorImpuesto = $campoImpuesto ? (float) ($extra?->{$campoImpuesto} ?? 0) : 0;

            $montoDescuento = (int) ($extra?->monto_descuento ?? 0);
            $totalDescuento += $montoDescuento;

            $lote = (string) ($linea->batch ?? '');
            if ((string) $producto?->tobatch === '0') {
                $lote = '';
            }

            $items[] = [
                'qty' => (float) $linea->qty,
                'descuento' => $montoDescuento,
                'total_iva' => (int) $linea->total_tva,
                'total_base' => (int) $linea->price,
                'total_impuestos' => (int) $linea->total_localtax1,
                'total' => (int) $linea->total_ttc,
                'total_mas_impuesto' => (int) $linea->total_ttc + (int) $valorImpuesto,
                'label' => $producto?->label,
                'ref' => $producto?->ref,
                'lote' => $lote,
                'stock' => collect(json_decode((string) $linea->stock_json, true) ?? [])
                    ->map(fn ($s) => ['idAlmacen' => (int) $s['idAlmacen'], 'cantidad' => (int) $s['cantidad']])
                    ->values()
                    ->all(),
                'codigo_barras' => $producto?->extrafields?->codigo_barras ?? ($producto?->ref ?? ''),
                'id_producto' => (int) ($producto?->rowid ?? 0),
                'devolucion' => (int) ($devoluciones[$linea->fk_product] ?? 0),
            ];
        }

        return ['items' => $items, 'totalDescuento' => $totalDescuento];
    }

    /**
     * Cantidad devuelta por producto a traves de notas credito
     * (facturas cuyo fk_facture_source apunta a esta factura).
     */
    private function cantidadesDevueltas(int $idFactura): array
    {
        $idsNotasCredito = Factura::query()->where('fk_facture_source', $idFactura)->pluck('rowid');

        if ($idsNotasCredito->isEmpty()) {
            return [];
        }

        return FacturaDetalle::query()
            ->whereIn('fk_facture', $idsNotasCredito)
            ->selectRaw('fk_product, SUM(qty) as qty')
            ->groupBy('fk_product')
            ->pluck('qty', 'fk_product')
            ->all();
    }

    /**
     * IVA/impoconsumo agrupados por tarifa, mas los impuestos extra
     * (IBUA, ICL, ADV, ICUI, etc.) agrupados por tipo.
     */
    private function impuestosFactura(int $idFactura): array
    {
        $impuestos = [];

        $porTarifa = FacturaDetalle::query()
            ->where('fk_facture', $idFactura)
            ->selectRaw('SUM(total_ht) as base, tva_tx, SUM(total_tva) as iva_total, localtax1_tx, SUM(total_localtax1) as impoconsumo_total')
            ->groupBy('tva_tx', 'localtax1_tx')
            ->get();

        foreach ($porTarifa as $fila) {
            $impuestos[] = [
                'tipo' => 'iva',
                'tarifa' => (int) $fila->tva_tx,
                'base' => (int) round((float) $fila->base),
                'valor' => (int) round((float) $fila->iva_total),
            ];

            if ((int) $fila->localtax1_tx !== 0) {
                $impuestos[] = [
                    'tipo' => 'impuesto',
                    'tarifa' => (int) $fila->localtax1_tx,
                    'base' => (int) round((float) $fila->base),
                    'valor' => (int) round((float) $fila->impoconsumo_total),
                ];
            }
        }

        // El prefijo de tabla (ej. "llx_") lo aplica Eloquent automaticamente en
        // join()/where(), pero no dentro de un selectRaw(); por eso se arma a mano aqui.
        $prefijo = (new FacturaDetalle)->getConnection()->getTablePrefix();

        $filasExtra = FacturaDetalle::query()
            ->join('facturedet_extrafields as fde', 'fde.fk_object', '=', 'facturedet.rowid')
            ->join('integros_product_impuestos_extra as ie', function ($join) {
                $join->on('ie.fk_product', '=', 'facturedet.fk_product')->where('ie.estado', 1);
            })
            ->where('facturedet.fk_facture', $idFactura)
            ->selectRaw("
                {$prefijo}facturedet.total_ht as total_ht,
                {$prefijo}ie.fk_type as fk_type,
                {$prefijo}ie.tarifa as tarifa,
                CASE {$prefijo}ie.fk_type
                    WHEN 1 THEN IFNULL({$prefijo}fde.ibua, 0)
                    WHEN 2 THEN IFNULL({$prefijo}fde.icl, 0)
                    WHEN 3 THEN IFNULL({$prefijo}fde.adv, 0)
                    WHEN 4 THEN IFNULL({$prefijo}fde.icui, 0)
                    WHEN 5 THEN IFNULL({$prefijo}fde.impuesto_consumo, 0)
                    WHEN 6 THEN IFNULL({$prefijo}fde.otros_impuestos, 0)
                    ELSE 0
                END as valor_impuesto
            ")
            ->get();

        $agrupados = [];

        foreach ($filasExtra as $fila) {
            $valor = (float) $fila->valor_impuesto;

            if ($valor <= 0) {
                continue;
            }

            $tipo = (int) $fila->fk_type;

            if (!isset($agrupados[$tipo])) {
                $agrupados[$tipo] = [
                    'tipo' => self::NOMBRE_IMPUESTO_EXTRA[$tipo] ?? 'Otros',
                    'tarifa' => (float) $fila->tarifa,
                    'base' => 0.0,
                    'valor' => 0.0,
                ];
            }

            $agrupados[$tipo]['base'] += (float) $fila->total_ht;
            $agrupados[$tipo]['valor'] += $valor;
        }

        $totalImpuestosExtra = 0.0;

        foreach ($agrupados as $impuestoExtra) {
            $impuestos[] = [
                'tipo' => $impuestoExtra['tipo'],
                'tarifa' => $impuestoExtra['tarifa'],
                'base' => (int) round($impuestoExtra['base']),
                'valor' => (int) round($impuestoExtra['valor']),
            ];
            $totalImpuestosExtra += $impuestoExtra['valor'];
        }

        return ['impuestos' => $impuestos, 'totalImpuestosExtra' => $totalImpuestosExtra];
    }
}
