<?php

namespace App\Http\Controllers\Domicilio;

use App\Http\Controllers\Controller;
use App\Services\Domicilio\DomicilioServices;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class domicilioController extends Controller
{
    public function __construct(
        private DomicilioServices $domicilioServices,
        private UsuariosService $usuariosService,
    ) {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/ticket/domicilio',
        summary: 'Confirma una factura del POS como pedido a domicilio',
        security: [['DolApiKey' => []]],
        tags: ['Domicilio'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'ref', type: 'string', description: 'Referencia de la factura', example: 'FA2601-0001'),
                    new OA\Property(property: 'direccionEntrega', type: 'string', example: 'Calle 1 # 2-3'),
                    new OA\Property(property: 'telefonoEntrega', type: 'string', example: '3001234567'),
                    new OA\Property(property: 'detalleEntrega', type: 'string', example: 'Torre 2, apto 301'),
                    new OA\Property(property: 'numeroBolsas', type: 'integer', example: 2),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Domicilio confirmado',
                content: new OA\JsonContent(example: [
                    'message' => 'Domicilio confirmado',
                    'status' => 0,
                ])
            ),
            new OA\Response(response: 401, description: "Falta DOLAPIKEY, el usuario no tiene el permiso 'backend', la factura no existe, ya tiene domicilio confirmado, o los datos enviados son incorrectos"),
        ]
    )]
    public function postPedidoDomicilio(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->domicilioServices->confirmarDomicilio($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/domicilios/getDomiciliosPos',
        summary: 'Listado paginado de domicilios, con datos de la factura, el vendedor y el domiciliario asignado',
        security: [['DolApiKey' => []]],
        tags: ['Domicilio'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'where', type: 'array', items: new OA\Items(type: 'object'), description: 'Filtros estilo Syncfusion DataManager sobre columnas de domicilios'),
                    new OA\Property(property: 'sorted', type: 'array', items: new OA\Items(type: 'object'), description: 'Orden estilo Syncfusion: [{name, direction}]'),
                    new OA\Property(property: 'skip', type: 'integer', example: 0),
                    new OA\Property(property: 'take', type: 'integer', example: 50),
                    new OA\Property(property: 'date_valid_ini', type: 'string', example: '2026-07-01'),
                    new OA\Property(property: 'date_valid', type: 'string', example: '2026-07-31'),
                    new OA\Property(property: 'domiciliario', type: 'string', description: "'T' para todos los domiciliarios, o el rowid de un domiciliario especifico", example: 'T'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de domicilios',
                content: new OA\JsonContent(example: [
                    'rowData' => [[
                        'rowid' => 1,
                        'fk_facture' => 10,
                        'direccion_entrega' => 'Calle 1 # 2-3',
                        'telefono_entrega' => '3001234567',
                        'detalle_entrega' => 'Torre 2, apto 301',
                        'numero_bolsas' => 2,
                        'fk_tipo_domicilio' => 1,
                        'estado' => 1,
                        'fk_domiciliario' => 5,
                        'fecha_asignacion' => '2026-07-07 10:00:00',
                        'fecha_entrega' => null,
                        'comentario' => null,
                        'auditoria' => null,
                        'ref' => 'FA2601-0001',
                        'datec' => '2026-07-07 09:00:00',
                        'total_ttc' => 50000.0,
                        'idVendedor' => 1,
                        'nomVendedor' => 'Andres Ardila',
                        'nomDomiciliario' => 'Carlos Perez',
                    ]],
                    'rowCount' => 1,
                ])
            ),
            new OA\Response(response: 401, description: 'Falta DOLAPIKEY o la clave no corresponde a ningun usuario activo'),
        ]
    )]
    public function getDomiciliosPos(Request $request)
    {
        return response()->json($this->domicilioServices->listarDomiciliosPos($request));
    }
}
