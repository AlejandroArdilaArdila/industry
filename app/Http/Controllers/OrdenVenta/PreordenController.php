<?php

namespace App\Http\Controllers\OrdenVenta;

use App\Http\Controllers\Controller;
use App\Services\OrdenVenta\PreordenService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PreordenController extends Controller
{
    public function __construct(
        private PreordenService $preordenService,
        private UsuariosService $usuariosService,
    ) {}

    #[OA\Post(
        path: '/api/api.php/integrospos/preorden/crear',
        summary: 'Crea una PRE-ORDEN de venta en el ERP (modulo integrospresta: llx_integros_precommande) en borrador, con ref PRE-YYYY-NNNN',
        security: [['DolApiKey' => []]],
        tags: ['OrdenVenta'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_reference', 'lines'],
                properties: [
                    new OA\Property(property: 'customerId', type: 'integer', description: 'rowid del cliente. Obligatorio si no se envia siren', example: 246),
                    new OA\Property(property: 'siren', type: 'string', description: 'Documento (NIT) del cliente. Alternativa a customerId', example: '99999999'),
                    new OA\Property(property: 'order_reference', type: 'string', description: 'Referencia UNICA del pedido origen (max 255). Si ya existe responde 409 con existing_preorder_id', example: 'APK-20-20260921103000-1'),
                    new OA\Property(property: 'origen_orden', type: 'string', description: 'Origen de la orden. Default "1"', example: '1'),
                    new OA\Property(property: 'customer_email', type: 'string', description: 'Opcional. Si coincide con un contacto activo se guarda su contact_id', example: 'cliente@correo.com'),
                    new OA\Property(property: 'employeeId', type: 'integer', description: 'Opcional. Usuario autor; por defecto el dueño del DOLAPIKEY', example: 20),
                    new OA\Property(property: 'notaPrivada', type: 'string', example: ''),
                    new OA\Property(property: 'notaPublica', type: 'string', example: ''),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'idProduct', type: 'integer', description: 'rowid del producto. Obligatorio si no se envia ref', example: 5),
                            new OA\Property(property: 'ref', type: 'string', description: 'Referencia del producto. Alternativa a idProduct', example: '000001'),
                            new OA\Property(property: 'cant', type: 'number', description: 'Cantidad (> 0)', example: 3),
                            new OA\Property(property: 'precio', type: 'number', description: 'Opcional. Precio unitario SIN IVA. Si no viene se toma el precio de venta del producto', example: 23429),
                            new OA\Property(property: 'tva_tx', type: 'number', description: 'Opcional. % IVA. Si no viene se toma el del producto', example: 5),
                            new OA\Property(property: 'discount', type: 'number', description: 'Opcional. % de descuento de la linea. Default 0', example: 0),
                            new OA\Property(property: 'description', type: 'string', description: 'Opcional. Default: descripcion/label del producto', example: ''),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pre-orden creada en borrador',
                content: new OA\JsonContent(example: [
                    'success' => true,
                    'id' => 12,
                    'ref' => 'PRE-2026-0012',
                    'status' => 'draft',
                    'customer_id' => 246,
                    'customer_siren' => '99999999',
                    'order_reference' => 'APK-20-20260921103000-1',
                    'origen_orden' => '1',
                    'customer_email' => '',
                    'contact_id' => 0,
                    'total_ht' => 70287.0,
                    'total_tva' => 3514.35,
                    'total_ttc' => 73801.35,
                    'lines_added' => 1,
                    'lines' => [[
                        'idLinea' => 31, 'idProduct' => 5, 'ref' => '000001', 'label' => 'PRODUCTO', 'cant' => 3.0,
                        'precio' => 23429.0, 'tva_tx' => 5.0, 'discount' => 0.0,
                        'total_ht' => 70287.0, 'total_tva' => 3514.35, 'total_ttc' => 73801.35,
                    ]],
                ])
            ),
            new OA\Response(response: 401, description: 'Datos invalidos: cliente/producto inexistente, lines vacio, cant <= 0, producto duplicado, etc.'),
            new OA\Response(response: 409, description: 'order_reference ya existe (code DUPLICATE_ORDER_REFERENCE, existing_preorder_id)'),
        ]
    )]
    public function crear(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->preordenService->crear($request, $usuario));
    }
}
