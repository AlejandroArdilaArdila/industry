<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Services\Facturacion\CotizacionService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CotizacionController extends Controller
{
    public function __construct(
        private CotizacionService $cotizacionService,
        private UsuariosService $usuariosService,
    ) {}

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/cotizacion/list',
        summary: 'Listado liviano de cotizaciones guardadas (borradores PROVxxx, fk_statut=0): sin items, sin metodo de pago, sin domicilio',
        security: [['DolApiKey' => []]],
        tags: ['Cotizacion'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'fk_soc', type: 'integer', description: 'Filtra por cliente', example: 3),
                    new OA\Property(property: 'limit', type: 'integer', description: 'Limite de resultados'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cotizaciones ordenadas por rowid descendente',
                content: new OA\JsonContent(example: [
                    'rows' => 1,
                    'cotizaciones' => [[
                        'rowid' => 115446,
                        'ref' => '(PROV115446)',
                        'refLocal' => '20-17072026144600-0009',
                        'bloqueoUsuario' => 0,
                        'bloqueoUsuarioLogin' => null,
                        'nombreCliente' => 'Consumidor final',
                        'documentoCliente' => '2222222222222',
                        'notaPrivada' => '',
                        'fecha' => '2026-07-17 14:46:20',
                        'total' => 66700,
                    ]],
                ])
            ),
        ]
    )]
    public function listar(Request $request)
    {
        $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->cotizacionService->listar($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/cotizacion/detalle',
        summary: 'Detalle (items) de una cotizacion guardada',
        security: [['DolApiKey' => []]],
        tags: ['Cotizacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idFacture'],
                properties: [
                    new OA\Property(property: 'idFacture', type: 'integer', example: 115446),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Encabezado + items de la cotizacion',
                content: new OA\JsonContent(example: [
                    'rowid' => 115446,
                    'ref' => '(PROV115446)',
                    'refLocal' => '20-17072026144600-0009',
                    'fecha' => '2026-07-17 14:46:20',
                    'nombreCliente' => 'Consumidor final',
                    'documentoCliente' => '2222222222222',
                    'notaPrivada' => '',
                    'totalProductos' => 3,
                    'total' => 66700,
                    'items' => [
                        ['idLinea' => 1, 'fk_product' => 25, 'referencia' => '000023', 'articulo' => 'GOMAS GRISSLY SOUR ACIDAS *72Gr', 'cantidad' => 1.0, 'precio' => 30000, 'iva' => 0, 'total' => 30000],
                        ['idLinea' => 2, 'fk_product' => 21, 'referencia' => '000021', 'articulo' => 'CHOC/LEYENDA EN BARRA CACO 90% 50G', 'cantidad' => 1.0, 'precio' => 13500, 'iva' => 0, 'total' => 13500],
                    ],
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'idFacture', o no existe una cotización con ese ID"),
        ]
    )]
    public function detalle(Request $request)
    {
        $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->cotizacionService->detalle($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/cotizacion/refPorRefLocal',
        summary: 'Resuelve la ref (PROVxxx) de la cotizacion provisional dueña de un ref_local',
        security: [['DolApiKey' => []]],
        tags: ['Cotizacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refLocal'],
                properties: [
                    new OA\Property(property: 'refLocal', type: 'string', example: '20-17072026144600-0009'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cotizacion encontrada',
                content: new OA\JsonContent(example: [
                    'idFactura' => 115446,
                    'refLocal' => '20-17072026144600-0009',
                    'ref' => '(PROV115446)',
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'refLocal'"),
            new OA\Response(response: 404, description: 'No existe una cotización provisional con ese refLocal'),
        ]
    )]
    public function refPorRefLocal(Request $request)
    {
        $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->cotizacionService->refPorRefLocal($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/cotizacion/validarEstado',
        summary: 'Consulta de solo lectura del estado de una cotizacion (bloqueo, en_pedido, si sigue provisional) antes de confirmarla, sin efectos secundarios',
        security: [['DolApiKey' => []]],
        tags: ['Cotizacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idFactura', 'idUsuario'],
                properties: [
                    new OA\Property(property: 'idFactura', type: 'integer', example: 184515),
                    new OA\Property(property: 'idUsuario', type: 'integer', description: 'Usuario que va a confirmar: si el bloqueo es de este mismo usuario, no cuenta como "bloqueada"', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estado actual de la cotizacion',
                content: new OA\JsonContent(example: [
                    'bloqueada' => false,
                    'bloqueoUsuario' => 0,
                    'bloqueoUsuarioLogin' => null,
                    'enPedido' => false,
                    'esProvisional' => true,
                    'ref' => '(PROV184515)',
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'idFactura'"),
            new OA\Response(response: 404, description: "'idFactura' no existe", content: new OA\JsonContent(example: ['error' => 'Factura no encontrada'])),
        ]
    )]
    public function validarEstado(Request $request)
    {
        $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->cotizacionService->validarEstado($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/cotizacion/item/eliminar',
        summary: 'Elimina una linea de la cotizacion',
        security: [['DolApiKey' => []]],
        tags: ['Cotizacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['fk_product'],
                properties: [
                    new OA\Property(property: 'fk_facture', type: 'integer', description: '0 si se resuelve por ref_local', default: 0),
                    new OA\Property(property: 'ref_local', type: 'string', description: 'Alternativa a fk_facture'),
                    new OA\Property(property: 'fk_product', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Linea eliminada (idempotente: si la factura no se encuentra por ref_local, responde OK igual)',
                content: new OA\JsonContent(example: ['fk_facture' => 9661, 'status' => 1, 'message' => 'OK'])
            ),
            new OA\Response(response: 401, description: "Falta 'fk_product', o no se indico 'fk_facture' ni 'ref_local'"),
        ]
    )]
    public function eliminarItem(Request $request)
    {
        $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->cotizacionService->eliminarItem($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/cotizacion/eliminar',
        summary: 'Elimina el borrador completo de una cotizacion',
        security: [['DolApiKey' => []]],
        tags: ['Cotizacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['id'],
                properties: [
                    new OA\Property(property: 'id', type: 'integer', description: 'fk_facture del borrador', example: 9664),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Borrador eliminado. Solo funciona sobre borradores (fk_statut=0, ref (PROVxxx))',
                content: new OA\JsonContent(example: ['status' => 1, 'message' => 'OK'])
            ),
            new OA\Response(response: 401, description: "Falta 'id'"),
        ]
    )]
    public function eliminarFactura(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->cotizacionService->eliminarFactura($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/factures/auditoria',
        summary: 'Historial de auditoria (cambios) de una cotizacion. Siempre responde 200 mientras la factura exista (auditoria:[] si nunca tuvo cambios registrados) — el cliente interpreta cualquier status distinto de 200 como "sin conexión"',
        security: [['DolApiKey' => []]],
        tags: ['Cotizacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['facid'],
                properties: [
                    new OA\Property(property: 'facid', type: 'integer', example: 115479),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Historial de cambios de la cotizacion, uno por cada vez que se guardo',
                content: new OA\JsonContent(example: [
                    'auditoria' => [
                        [
                            'fecha_pos' => '2026-07-21 15:57:00',
                            'fecha_sincronizado' => '2026-07-21 16:01:00',
                            'tuvo_error' => true,
                            'detalle_error' => 'HTTP 500: tiempo de espera agotado con el servidor',
                            'usuario' => 'emapari',
                            'tipo_cambio' => 'Cambio de cantidad',
                            'resumen' => 'Modificó la cantidad de GOMAS GRISSLY SOUR ACIDAS *72Gr (1 → 2) y se recalculó el descuento.',
                            'encabezado_anterior' => ['cliente' => 'Consumidor final', 'notaPrivada' => '', 'total' => 30000],
                            'encabezado_actual' => ['cliente' => 'Consumidor final', 'notaPrivada' => '', 'total' => 54600],
                            'lineas_anteriores' => [
                                ['ref' => '000023', 'articulo' => 'GOMAS GRISSLY SOUR ACIDAS *72Gr', 'cantidad' => 1, 'precio' => 30000, 'descuento' => 0, 'total' => 30000],
                            ],
                            'lineas_actuales' => [
                                ['ref' => '000023', 'articulo' => 'GOMAS GRISSLY SOUR ACIDAS *72Gr', 'cantidad' => 2, 'precio' => 27300, 'descuento' => 9, 'total' => 54600],
                            ],
                        ],
                    ],
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'facid'"),
            new OA\Response(response: 404, description: "'facid' no existe en absoluto (opcionalmente el cliente tambien puede tratar esto como auditoria vacia)"),
        ]
    )]
    public function auditoria(Request $request)
    {
        $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->cotizacionService->auditoria($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/facturepos/bloqueoCotizacion',
        summary: 'Marca/libera que usuario POS esta editando una cotizacion (facture_extrafields.bloqueo_usuario)',
        security: [['DolApiKey' => []]],
        tags: ['Cotizacion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idFactura', 'idUsuario'],
                properties: [
                    new OA\Property(property: 'idFactura', type: 'integer', description: 'Id (facture.rowid) de la factura provisional', example: 12345),
                    new OA\Property(property: 'idUsuario', type: 'integer', description: '0 pide liberar el bloqueo; solo lo puede liberar el mismo usuario (identificado por DOLAPIKEY) que lo tomo', example: 7),
                    new OA\Property(property: 'administrador', type: 'boolean', description: 'Solo aplica al liberar (idUsuario=0): si es true, se salta la validacion de dueño y libera la cotizacion sin importar quien la haya bloqueado', default: false),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Bloqueo guardado o liberado (mismo idUsuario que ya la tenia bloqueada, o quien la libera es el mismo dueño del bloqueo: idempotente)', content: new OA\JsonContent(example: ['ok' => true])),
            new OA\Response(response: 401, description: "Falta 'idFactura' o 'idUsuario', o falta/es invalido el header DOLAPIKEY"),
            new OA\Response(
                response: 400,
                description: 'La cotizacion ya esta bloqueada por otro usuario (al intentar bloquear), o quien pide liberarla (por DOLAPIKEY) no es quien la bloqueo',
                content: new OA\JsonContent(example: [
                    'error' => 'No puedes liberar la cotización porque no eres quien la bloqueó. Bloqueada por: Santiago Quintero.',
                ])
            ),
        ]
    )]
    public function bloqueoCotizacion(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->cotizacionService->bloqueoCotizacion($request, $usuario));
    }
}
