<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Services\Facturacion\FacturacionService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FacturacionController extends Controller
{
    public function __construct(
        private FacturacionService $facturacionService,
        private UsuariosService $usuariosService,
    ) {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/facturesPerUser',
        summary: 'Lista las facturas/tickets POS de un vendedor',
        tags: ['Facturacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['byUser'],
                properties: [
                    new OA\Property(property: 'byUser', type: 'string', description: 'Id del usuario vendedor (fk_user_author)', example: '5'),
                    new OA\Property(property: 'producto', type: 'string', description: 'Filtra por label/descripcion/ref/codigo de barras del producto', example: 'Coca Cola'),
                    new OA\Property(property: 'fk_caja_local', type: 'string', description: 'Filtra por referencia local del terminal de apertura/cierre'),
                    new OA\Property(property: 'cerrado', type: 'boolean', description: 'Si es true, solo facturas de terminales con estado=1 (cerrado)'),
                    new OA\Property(property: 'sortAD', type: 'string', enum: ['ascending', 'descending'], default: 'descending'),
                    new OA\Property(property: 'sortField', type: 'string', enum: ['rowid', 'ref', 'datec', 'total_tva', 'total_ht', 'total_ttc', 'fk_soc'], default: 'rowid'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de facturas del vendedor (o rows=0 con mensaje si no hay resultados)',
                content: new OA\JsonContent(example: [
                    'rows' => 1,
                    'factures' => [[
                        'rowid' => 120,
                        'ref' => '(PROV120)',
                        'date_valid' => '2026-07-01',
                        'total_iva' => 1900,
                        'total_sin_iva' => 10000,
                        'total_con_iva' => 11900,
                        'type' => 'F',
                        'metodoPago' => '[{"idMetodo":"1","valor":11900}]',
                        'domicilio' => false,
                        'domiDireccion' => '',
                        'domiTelefono' => '',
                        'domiBolsas' => 0,
                        'domiObservaciones' => '',
                        'domiTipo' => 0,
                        'domiTipoDescripcion' => '',
                        'esFactura' => true,
                        'fk_soc' => 3,
                        'nombreCliente' => 'Cliente generico(900123456)',
                        'fk_caja_local' => 'CAJA1',
                        'notaPrivada' => '',
                        'genera_domicilio' => false,
                    ]],
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'byUser' o el parámetro de ordenamiento no es válido"),
        ]
    )]
    public function listarFacturasApp(Request $request)
    {
        return response()->json($this->facturacionService->listarApp($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/detalleFactura',
        summary: 'Detalle completo de una factura/ticket POS (items, impuestos, QR/CUFE, puntos)',
        security: [['DolApiKey' => []]],
        tags: ['Facturacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'idFacture', type: 'integer', description: 'Id (rowid) de la factura', example: 120),
                    new OA\Property(property: 'ref', type: 'string', description: 'Referencia de la factura (busqueda parcial, LIKE %ref%)', example: '(PROV120)'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalle de la factura',
                content: new OA\JsonContent(example: [
                    'id' => 120,
                    'numerFactura' => 'FACTURA DE VENTA #(PROV120)',
                    'refLocal' => '',
                    'nombreCliente' => 'Cliente generico',
                    'documentoCliente' => '900123456',
                    'fecha' => '2026-07-01 10:00:00',
                    'cambio' => 0,
                    'efectivo' => 11900.0,
                    'totalPagado' => 11900,
                    'subTotal' => 10000,
                    'totalIva' => 1900,
                    'metodoPago' => [['metodo' => 'Efectivo', 'valor' => 11900]],
                    'otrosImpuestos' => 0,
                    'totalDescuento' => 0,
                    'QR' => 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=...',
                    'CUFE' => 'CUFE: e5bac48e354bc907bccff0ea7d45fbf784f0a8e7243b58337361e1fbd430489d',
                    'mensajeAlerta' => '',
                    'resolucion' => 'Resolución de facturación electrónica número 18760000001 habilitada desde 1 hasta 5000000 - Fecha inicial 2024-01-01 - Fecha final 2027-01-01',
                    'puntosAcumulados' => 0,
                    'puntosVenta' => 0,
                    'puntosRedimidos' => 0,
                    'puntosVencimiento' => 0,
                    'fechaPuntosVencimiento' => '2026-07-01',
                    'footerLegal' => '',
                    'footerGratitud' => '',
                    'footerIntegros' => 'Proveedor del software de facturación: Intégros 365 S.A.S. - Software propio',
                    'domiDireccion' => '',
                    'domiTelefono' => '',
                    'domiBolsas' => 0,
                    'domiObservaciones' => '',
                    'domiTipo' => 0,
                    'domiTipoDescripcion' => '',
                    'notaPrivada' => '',
                    'items' => [[
                        'qty' => 2.0,
                        'descuento' => 0,
                        'total_iva' => 1900,
                        'total_base' => 10000,
                        'total_impuestos' => 0,
                        'total' => 11900,
                        'total_mas_impuesto' => 11900,
                        'label' => 'Coca Cola 400ml',
                        'ref' => 'COCA400',
                        'lote' => '',
                        'codigo_barras' => '7702090001234',
                        'id_producto' => 45,
                        'devolucion' => 0,
                    ]],
                    'impuestos' => [[
                        'tipo' => 'iva',
                        'tarifa' => 19,
                        'base' => 10000,
                        'valor' => 1900,
                    ]],
                    'facturaOriginal' => '',
                ])
            ),
            new OA\Response(response: 401, description: "Falta idFacture/ref, no existe la factura, la resolucion no es valida, o el usuario no tiene el permiso 'backend' de integrospos"),
        ]
    )]

    public function detalleFactura(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->facturacionService->detalleFactura($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/ticket/payment/',
        summary: 'Crea un ticket/pedido POS completo (factura + lineas + pago + cierre) y genera sus asientos contables',
        security: [['DolApiKey' => []]],
        tags: ['Facturacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['customerId', 'employeeId', 'cashId', 'tipo_fac', 'refLocal', 'lines'],
                properties: [
                    new OA\Property(property: 'cashId', type: 'integer'),
                    new OA\Property(property: 'customerId', type: 'integer'),
                    new OA\Property(property: 'employeeId', type: 'integer'),
                    new OA\Property(property: 'notaPrivada', type: 'string'),
                    new OA\Property(property: 'refLocal', type: 'string', example: '11-060726082457-0004'),
                    new OA\Property(property: 'efectivo', type: 'number', default: 0),
                    new OA\Property(property: 'regreso', type: 'number', default: 0),
                    new OA\Property(property: 'direccionEntrega', type: 'string'),
                    new OA\Property(property: 'telefonoEntrega', type: 'string'),
                    new OA\Property(property: 'detalleEntrega', type: 'string'),
                    new OA\Property(property: 'numeroBolsas', type: 'integer', default: 0),
                    new OA\Property(property: 'fk_caja_local', type: 'string', description: 'Apertura de caja activa (opcional: el ticket tiene bypass from_app)', example: '11-02072026074324-0008'),
                    new OA\Property(property: 'tipo_fac', type: 'integer', enum: [1, 2, 3], description: '1=electronica, 2=pos, 3=borrador'),
                    new OA\Property(property: 'provisional', type: 'boolean', default: false),
                    new OA\Property(
                        property: 'lines',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'idProduct', type: 'integer'),
                            new OA\Property(property: 'cant', type: 'number'),
                            new OA\Property(property: 'lote', type: 'string'),
                            new OA\Property(property: 'precio', type: 'number'),
                            new OA\Property(property: 'nivelPrecio', type: 'string'),
                            new OA\Property(property: 'descuento', type: 'number'),
                            new OA\Property(property: 'discount', type: 'number'),
                            new OA\Property(property: 'observaciones', type: 'string'),
                            new OA\Property(property: 'tva_tx', type: 'number'),
                            new OA\Property(property: 'fk_plantilla', type: 'integer'),
                        ])
                    ),
                    new OA\Property(
                        property: 'metodoPago',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'idMetodo', type: 'integer'),
                            new OA\Property(property: 'valor', type: 'number'),
                        ])
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ticket creado, con el resultado de los asientos contables si la factura quedo valida',
                content: new OA\JsonContent(example: [
                    'idFactura' => 9662,
                    'ref' => '(PROV9662)',
                    'total_ht' => 30000,
                    'total_tva' => 0,
                    'total_ttc' => 30000,
                    'es_credito' => 0,
                    'id_coupon_final' => 0,
                    'status' => 1,
                    'message' => 'OK',
                    'asientos_status' => 1,
                    'asientos_message' => 'OK',
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'customerId'/'employeeId'/'tipo_fac'/'refLocal'/'lines', o DOLAPIKEY invalida"),
        ]
    )]
    public function validarFacturaPos(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);        
        return response()->json($this->facturacionService->validarFacturaPos($request, $usuario));
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/facturepos/dian/actualizarTransaccion/{idFactura}',
        summary: 'Arma o actualiza el registro de transaccion DIAN (CUFE/QR/JSON) de una factura ya creada/validada/pagada. El id tambien se puede mandar por query string (?idFactura=) contra la misma ruta sin el segmento',
        security: [['DolApiKey' => []]],
        tags: ['Facturacion'],
        parameters: [
            new OA\Parameter(name: 'idFactura', in: 'path', required: true, description: 'Id (rowid) de la factura ya creada', schema: new OA\Schema(type: 'integer'), example: 9662),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultado del SP sp_pos_dian_armar_json_factura (status=1 exito, status=2 fallo - la factura no se ve afectada)',
                content: new OA\JsonContent(example: [
                    'status' => 1,
                    'message' => 'OK',
                    'cufe' => 'e5bac48e354bc907bccff0ea7d45fbf784f0a8e7243b58337361e1fbd430489d',
                    'qr' => 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=...',
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'idFactura' o no es un id valido"),
        ]
    )]
    public function actualizarTransaccionDian(Request $request, ?int $idFactura = null)
    {
        if ($idFactura !== null) {
            $request->merge(['idFactura' => $idFactura]);
        }

        return response()->json($this->facturacionService->actualizarTransaccionDian($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/backup/pedido',
        summary: 'Respaldo/bitacora de un pedido confirmado en el POS offline (Flutter) - llamada adicional, en paralelo al sync de negocio de pos/ticket/payment',
        security: [['DolApiKey' => []]],
        tags: ['Backup'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refLocal'],
                properties: [
                    new OA\Property(property: 'refLocal', type: 'string', example: 'LOCAL-1720612345678'),
                    new OA\Property(property: 'numeroServidor', type: 'string', example: 'PROV1234'),
                    new OA\Property(property: 'fechaConfirmacion', type: 'string', example: '2026-07-10 14:32:10'),
                    new OA\Property(property: 'tipoFactura', type: 'integer', example: 3),
                    new OA\Property(property: 'cotizacion', type: 'boolean'),
                    new OA\Property(property: 'sincronizadoConErp', type: 'boolean'),
                    new OA\Property(property: 'datosPedido', type: 'object', description: 'Contenido integro de datos_json (PedidoConfirmado serializado)'),
                    new OA\Property(property: 'pedidoRequest', type: 'object', description: 'Contenido integro de pedido_request_json (mismo body enviado a ticket/payment)'),
                    new OA\Property(property: 'datosServer', type: 'object', description: 'Contenido de datos_server_json si ya sincronizo, o null'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Guardado (upsert por refLocal)', content: new OA\JsonContent(example: ['ok' => true])),
            new OA\Response(response: 401, description: "Falta 'refLocal'"),
        ]
    )]
    public function backupPedido(Request $request)
    {
        return response()->json($this->facturacionService->backupPedido($request));
    }

    #[OA\Post(
        path: '/api/pedidos/erp/validar-masivo',
        summary: 'Valida en lote si los pedidos locales existen en el ERP y si su ref/ref_local coinciden',
        tags: ['Facturacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(property: 'usuario_id', type: 'integer', description: 'Opcional; el usuario es el de la DOLAPIKEY', example: 1),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                        required: ['ref_local'],
                        properties: [
                            new OA\Property(property: 'ref_local', type: 'string', example: '15-170926090343-0024'),
                            new OA\Property(property: 'numero_servidor', type: 'string', nullable: true, example: 'OSOL10484'),
                            new OA\Property(property: 'id_factura', type: 'integer', nullable: true, example: 38633),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Objeto indexado por ref_local con { estado: coincide|no_coincide|no_existe|sin_referencia|error_conexion, erp: detalle|null }'),
            new OA\Response(response: 401, description: "Falta 'items' o algun items[].ref_local"),
        ]
    )]
    public function validarPedidosMasivo(Request $request)
    {
        return response()->json($this->facturacionService->validarPedidosMasivo($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/actualizarEnPedido',
        summary: 'Actualiza facture_extrafields.en_pedido (fk_object=facture.rowid) de una factura',
        tags: ['Facturacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idFactura'],
                properties: [
                    new OA\Property(property: 'idFactura', type: 'integer', description: 'Id (facture.rowid) de la factura', example: 120),
                    new OA\Property(property: 'en_pedido', type: 'integer', enum: [0, 1], default: 1, description: '1 deja en_pedido=1, 0 lo deja en NULL', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'en_pedido actualizado',
                content: new OA\JsonContent(example: ['ok' => true, 'idFactura' => 120, 'en_pedido' => 1])
            ),
            new OA\Response(response: 401, description: "Falta 'idFactura' o no existe una factura con ese ID"),
        ]
    )]
    public function actualizarEnPedido(Request $request)
    {
        return response()->json($this->facturacionService->actualizarEnPedido($request));
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/facturepos/dian/validarDescripciones/{idFactura}',
        summary: 'Revisa json_dian (electronica + POS) buscando lineas de invoice_lines con description en blanco/null y las completa con el label del producto (por ref). idFactura=0 procesa hasta 100 filas pendientes (validado NULL) por tabla; idFactura>0 procesa solo esa factura puntual, sin importar si ya estaba validada. El id tambien se puede mandar por query string (?idFactura=) contra la misma ruta sin el segmento',
        tags: ['Facturacion'],
        parameters: [
            new OA\Parameter(name: 'idFactura', in: 'path', required: true, description: '0 = todas las pendientes (limite 100 por tabla); >0 = solo esa factura', schema: new OA\Schema(type: 'integer'), example: 0),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resumen por tabla: filas revisadas, corregidas (rowid/fk_factura/refs) con su version en texto separada por saltos de linea, e ids invalidos (json_dian no parseable / sin invoice_lines)',
                content: new OA\JsonContent(example: [
                    'facturacione_config_factura_transaccion_dian' => ['revisadas' => 5, 'corregidas' => [], 'corregidas_texto' => '', 'invalidas' => []],
                    'facturacione_config_factura_pos_transaccion_dian' => [
                        'revisadas' => 100,
                        'corregidas' => [
                            ['rowid' => 11041, 'fk_factura' => 9662, 'refs' => ['000021', '000045']],
                        ],
                        'corregidas_texto' => "fk_factura=9662 ref=000021\nfk_factura=9662 ref=000045",
                        'invalidas' => [10998],
                    ],
                ])
            ),
        ]
    )]
    public function validarDescripcionesJsonDian(Request $request, ?int $idFactura = null)
    {
        if ($idFactura !== null) {
            $request->merge(['idFactura' => $idFactura]);
        }

        return response()->json($this->facturacionService->validarDescripcionesJsonDian((int) $request->input('idFactura', 0)));
    }

}
