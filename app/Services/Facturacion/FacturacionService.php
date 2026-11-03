<?php

namespace App\Services\Facturacion;

use App\Models\CuentaBancaria;
use App\Models\Factura;
use App\Models\FacturaExtrafields;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServiceGlobal\ServiceFuncionesService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\ServiceGlobal\ServiceGlobalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FacturacionService
{
    public function __construct(
        private ServiceGlobalService $serviceGlobal,
        private ServiceFuncionesService $serviceFunciones,
        private CotizacionService $cotizacionService,
    ) {}

    public function detalleFactura(Request $request, Usuario $usuario): array
    {
        if (! $usuario->tieneDerecho('backend', 'read')) {
            abort(401, 'Este usuario no tiene permisos para esta accion');
        }

        $idFacture = trim((string) $request->input('idFacture', ''));
        $referencia = trim((string) $request->input('ref', ''));

        if ($idFacture === '' && $referencia === '') {
            abort(401, 'El "idFacture" o la "referencia" es obligatoria para realizar la búsqueda.');
        }

        $idFactura = $referencia !== ''
            ? Factura::query()->where('ref', 'like', "%{$referencia}%")->value('rowid')
            : (int) $idFacture;

        if (! $idFactura) {
            abort(401, 'No existe una factura con esa referencia ni con ese ID, porfavor contacte al administrador.');
        }

        return $this->serviceFunciones->datosFactura((int) $idFactura);
    }

    public function listarApp(Request $request)
    {
        $byVendedor = (int) $request->input('byUser', 0);
        $byIdClient = $request->socid ? ((int) $request->input('socid', 0)) : ((int) $request->input('byIdClient', 0));
        $producto = trim((string) $request->input('producto', ''));
        $fkCajaLocal = trim((string) $request->input('fk_caja_local', ''));
        $cerrado = $request->boolean('cerrado');
        $provisional = $request->boolean('provisional');
        $limit = (int) $request->input('limit', 0);
        $facid = (int) $request->input('facid', 0);
        $items = $request->boolean('items');

        $facturas = Factura::query()
           // ->whereRaw("ref not like '(PROV%'")
            ->where(function ($q) {
                // type=0: facturas normales (respetan el filtro 'provisional' de abajo).
                // type=2: notas de credito, pero solo confirmadas/validadas (fk_statut >= 1),
                // nunca borradores.
                $q->where('type', 0)
                    ->orWhere(function ($q2) {
                        $q2->where('type', 2)->where('fk_statut', '>=', 1);
                    });
            })
            ->when($byVendedor !== 0, function ($q) use ($byVendedor) {
                $q->where('fk_user_author', $byVendedor);
            })
            ->when($byIdClient !== 0, function ($q) use ($byIdClient) {
                $q->where('fk_soc', $byIdClient)->limit(50);
            })
            ->when($facid !== 0, function ($q) use ($facid) {
                $q->where('rowid', $facid);
            })
            ->when($limit !== 0, function ($q) use ($limit) {
                $q->limit($limit);
            })
            ->when($fkCajaLocal !== '', function ($q) use ($fkCajaLocal) {
                $q->whereHas('extrafields.terminalAperturaCierre', function ($q2) use ($fkCajaLocal) {
                    $q2->where('ref_local', $fkCajaLocal);
                });
            })
            ->when($cerrado, function ($q) {
                $q->whereHas('extrafields.terminalAperturaCierre', function ($q2) {
                    $q2->where('estado', 1);
                });
            })
            ->when($provisional, function ($q) {
                $q->where('fk_statut', 0);
            })
            ->when($producto !== '', function ($q) use ($producto) {
                $q->whereHas('lineas.producto', function ($q2) use ($producto) {
                    $q2->where('label', 'like', "%{$producto}%")
                        ->orWhere('description', 'like', "%{$producto}%")
                        ->orWhere('ref', 'like', "%{$producto}%")
                        ->orWhereHas('barcodes', function ($q3) use ($producto) {
                            $q3->where('barcode', 'like', "%{$producto}%");
                        });
                });
            })
            ->select(['rowid', 'ref', 'type', 'datec', 'total_tva', 'total_ht', 'total_ttc', 'fk_soc', 'note_private'])
            ->with([
                'extrafields' => fn ($q) => $q->select('rowid', 'fk_object', 'fk_apertura', 'tipo_fac', 'metodoPago', 'ref_local'),
                'extrafields.terminalAperturaCierre' => fn ($q) => $q->select('rowid', 'ref_local'),
                'domicilio' => fn ($q) => $q->select('rowid', 'fk_facture', 'direccion_entrega', 'telefono_entrega', 'detalle_entrega', 'numero_bolsas', 'fk_tipo_domicilio'),
                'domicilio.tipoDomicilio' => fn ($q) => $q->select('rowid', 'tipo'),
                'sociedad' => fn ($q) => $q->select('rowid', 'nom', 'siren'),
            ])
            ->when($items, function ($q) {
                $q->with([
                    'lineas' => fn ($q2) => $q2->select(['rowid', 'fk_facture', 'fk_product', 'label', 'description', 'qty', 'price', 'subprice', 'subprice_ttc', 'tva_tx', 'remise_percent', 'total_ht', 'total_tva', 'total_ttc']),
                    'lineas.producto' => fn ($q2) => $q2->select(['rowid', 'ref', 'label']),
                    'lineas.extrafields' => fn ($q2) => $q2->select(['rowid', 'fk_object', 'tipo_descuento', 'monto_descuento', 'icui', 'ibua', 'icl', 'adv', 'otros_impuestos', 'fk_plantilla_contable']),
                    'lineas.extrafields.plantillaContable' => fn ($q2) => $q2->select(['rowid', 'ref']),
                ]);
            })
            ->withCount([
                'lineas as lineas_count',
                'lineas as lineas_domicilio_count' => function ($q) {
                    $q->whereHas('productoDomicilio');
                },
            ])
            ->orderByDesc('rowid')
            ->get();

        if ($facturas->isEmpty()) {
            return [
                'rows' => 0,
                'factures' => [],
                'mensaje' => 'No se encontraron facturas con los filtros indicados',
            ];
        }

        $metodosDisponibles = CuentaBancaria::query()
            ->select('rowid', 'ref', 'label')
            ->orderByRaw("(ref = 'LIQ') DESC")
            ->orderBy('ref')
            ->get()
            ->map(fn ($m) => [
                'id' => (int) $m->rowid,
                'code' => $m->ref,
                'metodo' => $m->label,
            ]);

        $objFacturas = [];

        foreach ($facturas as $factura) {
            $tipoFac = $factura->extrafields?->tipo_fac;
            $tipo = '';
            if ((int) $factura->type === 2) {
                $tipo = 'NC';
            } elseif ($tipoFac == '1') {
                $tipo = 'F';
            } elseif ($tipoFac == '2') {
                $tipo = 'P';
            } elseif ($tipoFac == '3') {
                $tipo = 'B';
            }

            $domicilio = $factura->domicilio;
            $terminal = $factura->extrafields?->terminalAperturaCierre;

            // true solo cuando la factura tiene EXACTAMENTE una linea y esa linea
            // es un producto de tipo domicilio (llx_pos_tipo_domicilio) - en ese
            // caso no hay nada mas que entregar. 'genera_domicilio' (abajo) es lo
            // INVERSO de esto: soloEsDomicilio=true => genera_domicilio=false
            // (esa factura no puede/no debe generar un domicilio).
            $soloEsDomicilio = $factura->lineas_count === 1 && $factura->lineas_domicilio_count === 1;

            $objFacturas[] = [
                'rowid' => $factura->rowid,
                'ref' => $factura->ref,
                'date_valid' => $factura->datec ?? date('Y-m-d'),
                'total_iva' => (int) $factura->total_tva,
                'total_sin_iva' => (int) $factura->total_ht,
                'total_con_iva' => (int) $factura->total_ttc,
                'type' => $tipo,
                'metodoPago' => collect(json_decode((string) $factura->extrafields?->metodoPago, true) ?? [])
                    ->map(function ($linea) use ($metodosDisponibles) {
                        $cuenta = $metodosDisponibles->first(fn ($m) => $m['id'] === (int) ($linea['idMetodo'] ?? 0));

                        return array_merge($linea, ['metodo' => $cuenta['metodo'] ?? 'Método no encontrado']);
                    })
                    ->values()
                    ->all(),
                'domicilio' => ! is_null($domicilio?->direccion_entrega),
                'domiDireccion' => $domicilio?->direccion_entrega ?? '',
                'domiTelefono' => $domicilio?->telefono_entrega ?? '',
                'domiBolsas' => (int) ($domicilio?->numero_bolsas ?? 0),
                'domiObservaciones' => $domicilio?->detalle_entrega ?? '',
                'domiTipo' => (int) ($domicilio?->fk_tipo_domicilio ?? 0),
                'domiTipoDescripcion' => $domicilio?->tipoDomicilio?->tipo ?? '',
                'esFactura' => (int) $factura->type === 0,
                'fk_apertura' => $factura->extrafields->fk_apertura,
                'ref_local' => $factura->extrafields->ref_local,
                'fk_soc' => (int) $factura->fk_soc,
                'nombreCliente' => $factura->sociedad ? "{$factura->sociedad->nom}({$factura->sociedad->siren})" : null,
                'fk_caja_local' => $terminal?->ref_local ?? '',
                'notaPrivada' => $factura->note_private ?? '',
                // Inverso de $soloEsDomicilio: false cuando la factura es
                // solamente el producto de domicilio (nada que entregar).
                'genera_domicilio' => ! $soloEsDomicilio,
                'items' => $items ? $this->itemsFacturaListado($factura) : [],
            ];
        }

        return [
            'rows' => count($objFacturas),
            'pages' => 1,
            'factures' => $objFacturas,
        ];
    }

    /**
     * Lineas de la factura tal como quedaron en facturedet/facturedet_extrafields,
     * sin el formateo "humano" de datosFactura() (para listarApp con items=true).
     */
    private function itemsFacturaListado(Factura $factura): array
    {
        return $factura->lineas
            ->map(function ($linea) {
                $extra = $linea->extrafields;
                $plantilla = $extra?->plantillaContable;

                return [
                    'rowid' => (int) $linea->rowid,
                    'fk_product' => (int) $linea->fk_product,
                    'ref' => $linea->producto?->ref ?? '',
                    'label' => $linea->producto?->label ?? $linea->label,
                    'description' => $linea->description,
                    'qty' => (float) $linea->qty,
                    'price' => (int) $linea->price,
                    'subprice' => (int) $linea->subprice,
                    'price_ttc' => (int) $linea->subprice_ttc,
                    'tva_tx' => (float) $linea->tva_tx,
                    'tipo_descuento' => (string) ($extra?->tipo_descuento ?? '0'),
                    'remise_percent' => (float) $linea->remise_percent,
                    'monto_descuento' => (int) ($extra?->monto_descuento ?? 0),
                    'total_ht' => (int) $linea->total_ht,
                    'total_tva' => (int) $linea->total_tva,
                    'total_ttc' => (int) $linea->total_ttc,
                    'icui' => (int) ($extra?->icui ?? 0),
                    'ibua' => (int) ($extra?->ibua ?? 0),
                    'icl' => (int) ($extra?->icl ?? 0),
                    'adv' => (int) ($extra?->adv ?? 0),
                    'otros_impuestos' => (int) ($extra?->otros_impuestos ?? 0),
                    'fk_plantilla_override' => (int) ($extra?->fk_plantilla_contable ?? 0),
                    'plantilla' => $plantilla ? ['rowid' => (int) $plantilla->rowid, 'ref' => $plantilla->ref] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Toda la logica de negocio (validaciones, resolucion/idempotencia de refLocal,
     * interpretacion de errores del SP, auditoria de cotizaciones, armado de la
     * respuesta) vive en sp_pos_validar_factura_pos. Esta funcion solo lee el
     * Request, arma los parametros, llama al SP, y traduce la "razon" que devuelve
     * a un codigo HTTP - eso ultimo no puede vivir en SQL.
     */
    public function validarFacturaPos(Request $request, Usuario $usuario)
    {
        $sincronizacion = $request->boolean('sincronizacion');

        $tipoFac = (int) $request->input('tipo_fac', 0);
        $modoFactura = $this->resolverModoTicket($request, $tipoFac);
        $eliminarItems = $request->boolean('eliminarItems');
        $esCotizacion = $request->boolean('cotizacion') || $request->boolean('esCotizacion');
        $refLocalApertura = trim((string) $request->input('fk_caja_local', ''));

        $lineasSp = collect($request->input('lines'))->map(fn ($linea) => [
            'idProduct' => (int) ($linea['idProduct'] ?? 0),
            'cant' => (float) ($linea['cant'] ?? 0),
            'precio' => $linea['precio'] ?? null,
            'tva_tx' => $linea['tva_tx'] ?? null,
            'nivelPrecio' => $linea['nivelPrecio'] ?? null,
            'descuento' => $linea['descuento'] ?? 0,
            'discount' => $linea['discount'] ?? 0,
            'accion' => $linea['accion'] ?? 'guardar',
            'lote' => $linea['lote'] ?: null,
            'fk_plantilla' => (int) ($linea['fk_plantilla'] ?? 0),
            'observaciones' => $linea['observaciones'] ?? null,
            'note' => $linea['note'] ?? null,
            // Linea ya calculada por el cliente (cotizaciones/modo provisional):
            // sp_pos_crear_lineas_factura_bulk las inserta tal cual, sin recalcular
            // nivel de precio, descuento global de producto, IVA/localtax ni
            // impuestos extra - por eso estos valores van SIN transformar (ya son
            // finales, no unitarios que haya que multiplicar por cant).
            'localtax1_tx' => $linea['localtax1_tx'] ?? null,
            'localtax1_type' => $linea['localtax1_type'] ?? null,
            'localtax2_tx' => $linea['localtax2_tx'] ?? null,
            'localtax2_type' => $linea['localtax2_type'] ?? null,
            // Fallback a los nombres que el cliente YA calculaba y mandaba antes
            // de que existieran estos campos (nivelPrecioSinIva/ConIva,
            // precioTotalSinIva, ivaTotal/valorIva/ivaConDescuento,
            // precioTotalConDescuento) - evita depender de que el cliente
            // agregue campos nuevos cuando ya tiene el valor calculado.
            'descuentoPorcentaje' => $linea['descuentoPorcentaje'] ?? $linea['discount'] ?? null,
            'precioUnitario' => $linea['precioUnitario'] ?? $linea['nivelPrecioSinIva'] ?? null,
            'precioUnitarioConIva' => $linea['precioUnitarioConIva'] ?? $linea['nivelPrecioConIva'] ?? null,
            'totalSinIva' => $linea['totalSinIva'] ?? $linea['precioTotalSinIva'] ?? null,
            'totalIva' => $linea['totalIva'] ?? $linea['ivaTotal'] ?? $linea['valorIva'] ?? $linea['ivaConDescuento'] ?? null,
            'totalConIva' => $linea['totalConIva'] ?? $linea['precioTotalConDescuento'] ?? null,
            'totalImpuestoLocal1' => $linea['totalImpuestoLocal1'] ?? null,
            'totalImpuestoLocal2' => $linea['totalImpuestoLocal2'] ?? null,
            'ibua' => $linea['ibua'] ?? null,
            'icl' => $linea['icl'] ?? null,
            'adv' => $linea['adv'] ?? null,
            'icui' => $linea['icui'] ?? null,
            'impuestoConsumo' => $linea['impuestoConsumo'] ?? null,
            'otrosImpuestos' => $linea['otrosImpuestos'] ?? null,
            // Retenciones de la linea (facturedet_extrafields.retefuente / .ica).
            'retefuente' => $linea['retefuente'] ?? null,
            'reteica' => $linea['reteica'] ?? null,
            'ventilation_retefuente' => $linea['ventilation_retefuente'] ?? null,
            'ventilation_reteica' => $linea['ventilation_reteica'] ?? null,
            'tarifa_retencion_aplicada' => $linea['tarifa_retencion_aplicada'] ?? null,
            'base_retencion_aplicada' => $linea['base_retencion_aplicada'] ?? null,
        ])->all();

        // Diagnostico: como todo confirmado/cotizacion ya se guarda tal cual sin
        // recalcular (ver sp_pos_crear_lineas_factura_bulk), un cliente que aun
        // no manda los campos estaticos deja subprice/total_ht/total_ttc en NULL
        // en llx_facturedet de forma silenciosa (el SP no lo rechaza). Se deja
        // registrado el body crudo para identificar que cliente/version esta
        // mandando esto, sin loguear en cada request (solo cuando falta algo).
        $lineaSinCalcular = collect($lineasSp)->first(fn ($linea) => $linea['precioUnitario'] === null
            && $linea['totalSinIva'] === null
            && $linea['totalIva'] === null
            && $linea['totalConIva'] === null);

        if ($lineaSinCalcular !== null) {
            $this->serviceGlobal->registrarTextoEnLog(
                'validarFacturaPos: linea sin campos estaticos',
                json_encode($request->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }

        $metodosPago = $request->input('metodoPago');

        $parametros = [
            $request->input('fk_facture', 0),
            $request->input('fecha', now()->format('Y-m-d H:i')),
            (int) $request->input('customerId'),
            (int) $request->input('employeeId'),
            '',
            $request->input('notaPrivada'),
            $request->filled('nivelPrecioGlobal') ? (string) $request->input('nivelPrecioGlobal') : null,
            $modoFactura !== 'provisional' ? (int) $request->input('cashId') : 0,
            json_encode($lineasSp),
            is_array($metodosPago) ? json_encode($metodosPago) : null,
            (string) $tipoFac,
            trim((string) $request->input('refLocal', '')),
            (float) $request->input('efectivo', 0),
            (float) $request->input('regreso', 0),
            $modoFactura,
            $tipoFac,
            0,
            $refLocalApertura !== '' && $modoFactura !== 'provisional' ? $refLocalApertura : null,
            $request->input('direccionEntrega') ?: null,
            $request->input('telefonoEntrega') ?: null,
            $request->input('detalleEntrega') ?: null,
            (int) $request->input('numeroBolsas', 0),
            (int) $request->input('tipoEntrega', 0),
            $request->filled('lat') ? (float) $request->input('lat') : null,
            $request->filled('lng') ? (float) $request->input('lng') : null,
            (int) $request->input('id_coupon', 0),
            (int) $request->input('total_field', 0),
            $eliminarItems,
            $esCotizacion,
            $request->boolean('autorizacion'),
            $request->boolean('app'),
            $request->input('fecha_pos'),
            $request->boolean('tuvo_error'),
            $request->input('detalle_error'),
            // Numeracion local: el servidor local (offline, con los datos de
            // resolucion/DIAN que ya le manda getUsersPos) ya calculo el
            // consecutivo y el CUFE/QR el mismo. sp_pos_crear_ticket_v1 los usa
            // tal cual (no genera un numero ni un CUFE nuevo) y el orquestador
            // responde minimo (success/idFactura/num_referencia) en vez del
            // detalle completo, porque el cliente ya tiene todo.
            $request->boolean('numeracionLocal'),
            $request->input('numFacLocal'),
            $request->filled('consecutivoLocal') ? (int) $request->input('consecutivoLocal') : null,
            $request->input('cufeLocal'),
            $request->input('qrLocal'),
            $request->input('resolucionLocal'),
            $request->input('resolucionNumeroLocal'),
            $request->input('softwareIdLocal'),
            $request->input('fechaFacturaLocal'),
            $request->input('notaPublica'),
            // Si viene true y el modo es provisional, el SP libera el bloqueo
            // (facture_extrafields.bloqueo_usuario = 0) despues de guardar la
            // cotizacion - lo mismo que hace bloqueoCotizacion(idUsuario=0),
            // pero en el mismo request que guarda en vez de una llamada aparte.
            $request->boolean('desbloquear'),
            // Flag EXPLICITO: el cliente ya calculo precio/IVA/descuento/totales
            // por linea (ver campos precioUnitario/totalSinIva/etc. en $lineasSp)
            // y no quiere que el SP los recalcule. NUNCA se infiere de 'modo'
            // /'provisional' - un cliente que aun no manda esos campos tambien
            // guarda cotizaciones con modo=provisional y necesita que se sigan
            // calculando como siempre.
            $request->boolean('lineasCalculadas'),
            // Retenciones del encabezado (facture_extrafields.retefuente / .reteica,
            // lo que lee compta/facture/card.php). null = no se mandaron: el SP
            // conserva lo que ya tenia la factura. reteiva no se maneja aqui.
            $request->filled('retefuente') ? (float) $request->input('retefuente') : null,
            $request->filled('reteica') ? (float) $request->input('reteica') : null,
        ];

        $resultado = $this->serviceGlobal->ejecutarProcedimientoUltimoResultado('sp_pos_validar_factura_pos', $parametros);

        $razon = $resultado['reason'] ?? null;
        $cuerpo = json_decode((string) ($resultado['response_json'] ?? '{}'), true) ?? [];

        // sp_pos_validar_factura_pos devuelve una "razon" semantica en vez de un
        // codigo HTTP directamente (MySQL no puede fijar un status HTTP).
        $codigoPorRazon = [
            'REQUIRED_FIELD' => 401,
            'FACTURAS_VENCIDAS' => 406,
            'CUPO_EXCEDIDO' => 407,
            'DUPLICATE_NUMBERING' => 409,
            'REJECTED' => 409,
            'AUTH_ERROR' => 400,
        ];

        $this->serviceGlobal->copiarSqlAlPortapapeles('sp_pos_validar_factura_pos', $parametros);

        if ($razon !== 'OK') {
            // Solo se copia a procedimientos.log/portapapeles cuando el SP fallo -
            // hacerlo siempre (exito incluido) hizo crecer el log a 200+MB.
            $this->serviceGlobal->copiarSqlAlPortapapeles('sp_pos_validar_factura_pos', $parametros);
            abort(response()->json($cuerpo, $codigoPorRazon[$razon] ?? 400));
        }

        return $cuerpo;
    }

    /**
     * 'provisional' si el request lo marca explicitamente, 'borrador' si
     * el tipo de documento es borrador (3), 'normal' en cualquier otro caso.
     */
    private function resolverModoTicket(Request $request, int $tipoFac): string
    {
        if ($request->boolean('provisional')) {
            return 'provisional';
        }

        return 'normal';
    }

    /**
     * Actualiza facture_extrafields.en_pedido (fk_object=facture.rowid):
     * en_pedido=1 -> 1, en_pedido=0 -> NULL.
     */
    public function actualizarEnPedido(Request $request): array
    {
        $idFactura = (int) $request->input('idFactura', 0);

        if ($idFactura <= 0) {
            abort(401, "El campo 'idFactura' es obligatorio.");
        }

        $ref = Factura::query()->where('rowid', $idFactura)->value('ref');

        if ($ref === null) {
            abort(401, 'No existe una factura con ese ID.');
        }

        if (! str_starts_with($ref, '(PROV')) {
            abort(401, 'Solo se puede marcar en_pedido sobre cotizaciones/borradores (ref "(PROVxxx)").');
        }

        $enPedido = $request->boolean('en_pedido', true) ? 1 : null;

        $extra = FacturaExtrafields::query()->where('fk_object', $idFactura)->first()
            ?? tap(new FacturaExtrafields, fn ($e) => $e->fk_object = $idFactura);
        $extra->en_pedido = $enPedido;
        $extra->save();

        return ['ok' => true, 'idFactura' => $idFactura, 'en_pedido' => $enPedido];
    }

    /**
     * POST /pedidos/erp/validar-masivo
     *
     * Valida contra el ERP un lote de pedidos locales: por cada item dice si la
     * factura `id_factura` existe y si su ref / ref_local coinciden con lo que
     * el cliente tiene guardado. Responde un objeto indexado por ref_local:
     *
     * - coincide:       ref_local igual y (si vino numero_servidor) ref igual.
     * - no_coincide:    la factura existe pero alguno de los dos no cuadra.
     * - no_existe:      no hay factura con ese id_factura.
     * - sin_referencia: no vino id_factura, no se consulta nada.
     * - error_conexion: fallo la consulta de ese item; el cliente debe reintentar.
     *
     * `erp` es el mismo objeto que devuelve el detalle de cotizacion
     * (CotizacionService::detalle). Las existencias/ref de todos los items se
     * resuelven en una sola consulta; el detalle solo se arma para los que existen.
     * El usuario es el de la DOLAPIKEY; `usuario_id` del body se acepta pero no
     * cambia nada aqui (lo usa el servidor local para elegir la sesion).
     */
    public function validarPedidosMasivo(Request $request): array
    {
        $items = $request->input('items');

        if (! is_array($items) || count($items) === 0) {
            abort(401, "El campo 'items' es obligatorio y debe ser un arreglo con al menos un elemento.");
        }

        $normalizados = [];
        foreach ($items as $i => $item) {
            $refLocal = trim((string) ($item['ref_local'] ?? ''));
            if ($refLocal === '') {
                abort(401, "items[{$i}].ref_local es obligatorio.");
            }

            $numeroServidor = trim((string) ($item['numero_servidor'] ?? ''));
            $idFactura = (int) ($item['id_factura'] ?? 0);

            $normalizados[] = [
                'ref_local' => $refLocal,
                'numero_servidor' => $numeroServidor !== '' ? $numeroServidor : null,
                'id_factura' => $idFactura > 0 ? $idFactura : null,
            ];
        }

        $idsFactura = collect($normalizados)->pluck('id_factura')->filter()->unique()->values()->all();

        $existentes = [];
        $errorConsulta = false;
        if (! empty($idsFactura)) {
            try {
                $existentes = Factura::query()
                    ->whereIn('rowid', $idsFactura)
                    ->pluck('rowid')
                    ->mapWithKeys(fn ($id) => [(int) $id => true])
                    ->all();
            } catch (\Throwable $e) {
                Log::error('validarPedidosMasivo: error consultando facturas: '.$e->getMessage());
                $errorConsulta = true;
            }
        }

        $detalles = [];
        $resultado = [];

        foreach ($normalizados as $item) {
            $idFactura = $item['id_factura'];

            if ($idFactura === null) {
                $resultado[$item['ref_local']] = ['estado' => 'sin_referencia', 'erp' => null];
                continue;
            }

            if ($errorConsulta) {
                $resultado[$item['ref_local']] = ['estado' => 'error_conexion', 'erp' => null];
                continue;
            }

            if (! isset($existentes[$idFactura])) {
                $resultado[$item['ref_local']] = ['estado' => 'no_existe', 'erp' => null];
                continue;
            }

            if (! array_key_exists($idFactura, $detalles)) {
                try {
                    $detalles[$idFactura] = $this->cotizacionService->detalle(new Request(['idFacture' => $idFactura]));
                } catch (HttpException|HttpResponseException $e) {
                    // detalle() aborta si la factura desaparecio entre las dos consultas.
                    $detalles[$idFactura] = false;
                } catch (\Throwable $e) {
                    Log::error("validarPedidosMasivo: error armando detalle de la factura {$idFactura}: ".$e->getMessage());
                    $detalles[$idFactura] = null;
                }
            }

            $erp = $detalles[$idFactura];

            if ($erp === false) {
                $resultado[$item['ref_local']] = ['estado' => 'no_existe', 'erp' => null];
                continue;
            }

            if ($erp === null) {
                $resultado[$item['ref_local']] = ['estado' => 'error_conexion', 'erp' => null];
                continue;
            }

            $coincide = (string) ($erp['refLocal'] ?? '') === $item['ref_local']
                && ($item['numero_servidor'] === null || (string) $erp['ref'] === $item['numero_servidor']);

            $resultado[$item['ref_local']] = [
                'estado' => $coincide ? 'coincide' : 'no_coincide',
                'erp' => $erp,
            ];
        }

        return $resultado;
    }

    /**
     * Respaldo/bitacora de pedidos_confirmados_local (Flutter), en paralelo
     * al sync de negocio de validarFacturaPos() - no lo reemplaza ni depende
     * de el. Upsert por refLocal via ON DUPLICATE KEY UPDATE: el reintento en
     * background del cliente puede reenviar el mismo registro si la
     * respuesta se perdio aunque el POST si llego.
     */
    public function backupPedido(Request $request): array
    {
        $refLocal = trim((string) $request->input('refLocal', ''));

        if ($refLocal === '') {
            abort(401, "El campo 'refLocal' es obligatorio.");
        }

        $prefijo = DB::connection('mysql')->getTablePrefix();

        DB::connection('mysql')->statement("
            INSERT INTO {$prefijo}pos_backup_pedidos
                (ref_local, numero_servidor, fecha_confirmacion, tipo_factura, cotizacion, sincronizado_erp, datos_pedido_json, pedido_request_json, datos_server_json, fecha_recibido)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                numero_servidor = VALUES(numero_servidor),
                fecha_confirmacion = VALUES(fecha_confirmacion),
                tipo_factura = VALUES(tipo_factura),
                cotizacion = VALUES(cotizacion),
                sincronizado_erp = VALUES(sincronizado_erp),
                datos_pedido_json = VALUES(datos_pedido_json),
                pedido_request_json = VALUES(pedido_request_json),
                datos_server_json = VALUES(datos_server_json),
                fecha_recibido = NOW()
        ", [
            $refLocal,
            $request->input('numeroServidor'),
            $request->input('fechaConfirmacion'),
            $request->filled('tipoFactura') ? (int) $request->input('tipoFactura') : null,
            $request->has('cotizacion') ? (int) $request->boolean('cotizacion') : null,
            $request->has('sincronizadoConErp') ? (int) $request->boolean('sincronizadoConErp') : null,
            $this->jsonNulable($request->input('datosPedido')),
            $this->jsonNulable($request->input('pedidoRequest')),
            $this->jsonNulable($request->input('datosServer')),
        ]);

        return ['ok' => true];
    }

    private function jsonNulable($valor): ?string
    {
        return $valor === null ? null : json_encode($valor);
    }

    public function actualizarTransaccionDian(Request $request): array
    {
        $idFactura = (int) $request->input('idFactura', 0);

        // El CUFE de un documento no deberia cambiar solo porque se reconstruyo
        // el JSON de nuevo (sp_pos_dian_armar_json_factura_interna ahora lo
        // reusa por defecto): 'regenerarCufe' es la unica forma de forzar un
        // recalculo, para cuando la DIAN rechazo el envio puntualmente por un
        // error EN EL CUFE y hay que generar uno nuevo. Aplica igual en el modo
        // batch (idFactura=0): con regenerarCufe=1 fuerza el recalculo de TODAS
        // las transacciones pendientes, no solo de una factura puntual.
        $regenerarCufe = $request->boolean('regenerarCufe');
        $fecha = $request->input('fecha');

        if ($idFactura === 0) {
            return $this->recalcularTransaccionesDianPendientes($regenerarCufe, $fecha);
        }

        if ($idFactura < 0) {
            abort(401, "El campo 'idFactura' es obligatorio.");
        }

        // facture.type=2 es nota de credito: usa su propio SP
        // (sp_pos_dian_armar_json_nc), que devuelve 'cude' en vez de 'cufe'
        // pero acepta el mismo p_regenerar_cufe que sp_pos_dian_armar_json_factura.
        if ($this->esNotaCredito($idFactura)) {
            $resultado = $this->serviceGlobal->ejecutarProcedimientoConResultado('sp_pos_dian_armar_json_nc', [$idFactura, $regenerarCufe]);
        } else {
            $resultado = $this->serviceGlobal->ejecutarProcedimientoConResultado('sp_pos_dian_armar_json_factura', [$idFactura, $regenerarCufe]);
        }

        if ((int) ($resultado['status'] ?? 0) !== 1) {
            Log::error('Fallo al armar el JSON DIAN de la factura', [
                'idFactura' => $idFactura,
                'message' => $resultado['message'] ?? null,
            ]);
        }

        return $resultado;
    }

    private function esNotaCredito(int $idFactura): bool
    {
        return (int) Factura::query()->where('rowid', $idFactura)->value('type') === 2;
    }

    /**
     * Modo batch de actualizarTransaccionDian(): idFactura=0 recalcula TODAS las
     * transacciones DIAN pendientes (estado=0 o estado=-1) tanto de factura normal
     * (facturacione_config_factura_transaccion_dian) como de factura POS
     * (facturacione_config_factura_pos_transaccion_dian), una por una via
     * sp_pos_dian_armar_json_factura (o sp_pos_dian_armar_json_nc si la factura
     * es una nota de credito, facture.type=2), y devuelve un resumen de la
     * ejecucion en vez del resultado de una sola factura.
     *
     * Antes de tomar los pendientes, cierra (estado=1) los que la DIAN ya rechazo
     * por "Documento procesado anteriormente": ese rechazo significa que el
     * documento SI fue procesado en un intento previo, asi que no es un pendiente
     * real y reintentarlo en cada corrida del batch no tiene sentido.
     *
     * $fecha (formato Y-m-d): si viene, limita el batch a las transacciones
     * creadas desde esa fecha en adelante (date_creation >= fecha), en vez de
     * recorrer TODO el historico de pendientes.
     */
    private function recalcularTransaccionesDianPendientes(bool $regenerarCufe = false, ?string $fecha = null): array
    {
        foreach ([
            'facturacione_config_factura_transaccion_dian',
            'facturacione_config_factura_pos_transaccion_dian',
        ] as $tabla) {
            DB::connection('mysql')
                ->table($tabla)
                ->where('estado', 0)
                ->where('response_dian', 'like', '%90, Rechazo: Documento procesado anteriormente%')
                ->when($fecha, fn ($query) => $query->where('date_creation', '>=', $fecha))
                ->update(['estado' => 1]);
        }

        $pendientes = DB::connection('mysql')
            ->table('facturacione_config_factura_transaccion_dian')
            ->whereIn('estado', [0])
            ->when($fecha, fn ($query) => $query->where('date_creation', '>=', $fecha))
            ->get(['rowid', 'fk_factura'])
            ->concat(
                DB::connection('mysql')
                    ->table('facturacione_config_factura_pos_transaccion_dian')
                    ->whereIn('estado', [0])
                    ->when($fecha, fn ($query) => $query->where('date_creation', '>=', $fecha))
                    ->get(['rowid', 'fk_factura'])
            );

        // Se resuelve el type (0=factura, 2=nota de credito) de todas las
        // facturas pendientes de una sola vez, en vez de una consulta por
        // cada una dentro del loop.
        $tiposPorFactura = Factura::query()
            ->whereIn('rowid', $pendientes->pluck('fk_factura')->unique())
            ->pluck('type', 'rowid');

        $exitosas = [];
        $fallidas = [];

        foreach ($pendientes as $pendiente) {
            $idFactura = (int) $pendiente->fk_factura;
            $esNotaCredito = (int) ($tiposPorFactura[$idFactura] ?? 0) === 2;

            try {
                $resultado = $esNotaCredito
                    ? $this->serviceGlobal->ejecutarProcedimientoConResultado('sp_pos_dian_armar_json_nc', [$idFactura, $regenerarCufe])
                    : $this->serviceGlobal->ejecutarProcedimientoConResultado('sp_pos_dian_armar_json_factura', [$idFactura, $regenerarCufe]);

                if ((int) ($resultado['status'] ?? 0) === 1) {
                    $exitosas[] = ['idFactura' => $idFactura, 'cufe' => $resultado['cufe'] ?? ($resultado['cude'] ?? null)];
                } else {
                    $fallidas[] = ['idFactura' => $idFactura, 'message' => $resultado['message'] ?? null];
                    Log::error('Fallo al recalcular el JSON DIAN de la factura (batch)', [
                        'idFactura' => $idFactura,
                        'message' => $resultado['message'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                $fallidas[] = ['idFactura' => $idFactura, 'message' => $e->getMessage()];
                Log::error('Excepcion al recalcular el JSON DIAN de la factura (batch)', [
                    'idFactura' => $idFactura,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return [
            'status' => 1,
            'message' => 'Recalculo masivo de transacciones DIAN pendientes completado.',
            'total_pendientes' => $pendientes->count(),
            'total_exitosas' => count($exitosas),
            'total_fallidas' => count($fallidas),
            'exitosas' => $exitosas,
            'fallidas' => $fallidas,
        ];
    }

    /**
     * Revisa json_dian (columna dataFactura.invoice_lines) de
     * facturacione_config_factura_transaccion_dian (electronica) y su
     * version POS, buscando lineas con 'description' en blanco/null: las
     * completa con el label del producto (llx_product.label) que tenga esa
     * misma 'ref'. Cada fila revisada queda marcada con validado=1 (haya
     * tenido lineas para corregir o no) o validado=-1 (json_dian no se pudo
     * parsear / no tiene la estructura esperada), para que la proxima
     * corrida solo tome las que todavia estan en NULL (whereNull('validado')).
     *
     * $idFactura=0: procesa como maximo 100 filas pendientes (validado NULL)
     * por tabla. $idFactura>0: ignora 'validado' y el limite de 100, y solo
     * procesa las filas de esa factura puntual (fk_factura), sin importar si
     * ya estaban validadas antes.
     */
    public function validarDescripcionesJsonDian(int $idFactura = 0): array
    {
        set_time_limit(300);

        $tablas = [
            'facturacione_config_factura_transaccion_dian',
            'facturacione_config_factura_pos_transaccion_dian',
        ];

        $cacheLabels = [];
        $obtenerLabel = function (string $ref) use (&$cacheLabels): ?string {
            if (! array_key_exists($ref, $cacheLabels)) {
                $cacheLabels[$ref] = Producto::query()->where('ref', $ref)->value('label');
            }

            return $cacheLabels[$ref];
        };

        $resumen = [];

        foreach ($tablas as $tabla) {
            $filas = DB::connection('mysql')
                ->table($tabla)
                ->whereNotNull('json_dian')
                ->where('json_dian', '!=', '')
                ->when(
                    $idFactura > 0,
                    fn ($q) => $q->where('fk_factura', $idFactura),
                    fn ($q) => $q->whereNull('validado')->orderBy('rowid')->limit(100)
                )
                ->get(['rowid', 'fk_factura', 'json_dian']);

            $revisadas = 0;
            $corregidas = [];
            $lineasTexto = [];
            $invalidas = [];

            foreach ($filas as $fila) {
                $revisadas++;

                $data = json_decode($fila->json_dian, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! isset($data['dataFactura']['invoice_lines']) || ! is_array($data['dataFactura']['invoice_lines'])) {
                    // validado=-1: no se pudo parsear/no tiene la estructura esperada.
                    // Se marca igual (no queda en NULL) para que no bloquee el cupo de
                    // 100 filas de las siguientes corridas; se distingue de validado=1
                    // (revisada y correcta) para poder inspeccionarla a mano despues.
                    DB::connection('mysql')->table($tabla)->where('rowid', $fila->rowid)->update(['validado' => -1]);
                    $invalidas[] = (int) $fila->rowid;

                    continue;
                }

                $refsCorregidas = [];

                foreach ($data['dataFactura']['invoice_lines'] as &$linea) {
                    $descripcion = trim((string) ($linea['description'] ?? ''));

                    if ($descripcion !== '') {
                        continue;
                    }

                    $ref = trim((string) ($linea['ref'] ?? ''));

                    if ($ref === '') {
                        continue;
                    }

                    $label = $obtenerLabel($ref);

                    if ($label) {
                        $linea['description'] = $label;
                        $refsCorregidas[] = $ref;
                    }
                }
                unset($linea);

                $update = ['validado' => 1];

                if (! empty($refsCorregidas)) {
                    $update['json_dian'] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $corregidas[] = [
                        'rowid' => (int) $fila->rowid,
                        'fk_factura' => (int) $fila->fk_factura,
                        'refs' => $refsCorregidas,
                    ];

                    foreach ($refsCorregidas as $ref) {
                        $lineasTexto[] = sprintf('fk_factura=%d ref=%s', (int) $fila->fk_factura, $ref);
                    }
                }

                DB::connection('mysql')->table($tabla)->where('rowid', $fila->rowid)->update($update);
            }

            $resumen[$tabla] = [
                'revisadas' => $revisadas,
                'corregidas' => $corregidas,
                'corregidas_texto' => implode("\n", $lineasTexto),
                'invalidas' => $invalidas,
            ];
        }

        return $resumen;
    }
}
