<?php

namespace App\Http\Controllers\Integrosone;

use App\Http\Controllers\Controller;
use App\Services\Integrosone\IntegrosoneService;
use App\Services\OrdenVenta\PreordenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class IntegrosoneController extends Controller
{
    public function __construct(
        private IntegrosoneService $integrosoneService,
        private PreordenService $preordenService,
    ) {
    }

    #[OA\Post(
        path: '/api/custom/integrosone/proveedores/login',
        summary: 'Inicia sesion de un contacto de proveedor con su correo y su clave; devuelve su APIKEY y los datos del contacto y del proveedor',
        tags: ['Integrosone'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', description: 'Correo del contacto (llx_socpeople.email)', example: 'contacto@proveedor.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sesion iniciada. Usar APIKEY como header DOLAPIKEY.',
                content: new OA\JsonContent(example: [
                    'APIKEY' => 'GX8g3Y7N2vxv4KDc9Zlb43lNs0O9WknL',
                    'acceso_id' => 7,
                    'debe_cambiar_clave' => false,
                    'contacto' => ['id' => 12, 'nombres' => 'Ana', 'apellidos' => 'Perez', 'email' => 'contacto@proveedor.com'],
                    'proveedor' => ['id' => 45, 'nit' => '900123456', 'razon_social' => 'Proveedor SAS'],
                ])
            ),
            new OA\Response(response: 401, description: 'Credenciales invalidas, o acceso/contacto/proveedor inactivo'),
            new OA\Response(response: 403, description: 'El acceso no tiene api_key configurada'),
            new OA\Response(response: 422, description: 'Faltan parametros'),
            new OA\Response(response: 429, description: 'Demasiados intentos'),
        ]
    )]
    public function loginProveedor(Request $request): JsonResponse
    {
        // El handler global (bootstrap/app.php) convierte ValidationException
        // en 500, asi que la validacion se responde aqui como 422.
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Parámetros inválidos.', 'errors' => $validator->errors()], 422);
        }

        [$status, $body] = $this->integrosoneService->loginProveedor(
            trim($request->input('email')),
            $request->input('password'),
        );

        return response()->json($body, $status);
    }

    #[OA\Get(
        path: '/api/api.php/integrosone/proveedores/{idProveedor}/productos',
        summary: 'Productos con precio por evento de un proveedor, ordenados por descripcion. Valida con la DOLAPIKEY del propio proveedor (la que devuelve su login)',
        security: [['DolApiKey' => []]],
        tags: ['Integrosone'],
        parameters: [
            new OA\Parameter(name: 'idProveedor', in: 'path', required: true, description: 'llx_societe.rowid del proveedor', schema: new OA\Schema(type: 'integer'), example: 45),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Productos del proveedor (lista vacia si no tiene precios por evento)',
                content: new OA\JsonContent(example: [
                    'total' => 1,
                    'productos' => [[
                        'id_producto' => 310,
                        'referencia' => 'ARR-001',
                        'descripcion' => 'ARROZ 500G',
                        'codigos_barras' => '7701234567890, 7701234567891',
                        'categorias' => [['id' => 12, 'nombre' => 'ABARROTES'], ['id' => 15, 'nombre' => 'GRANOS']],
                        'stock' => 120.0,
                        'embalaje' => 24.0,
                        'costo_evento_unidad' => 1500.0,
                        'precio_base_sin_iva' => 36000.0,
                        'pct_iva' => 19.0,
                        'pct_desc_proveedor' => 5.0,
                        'total_costo_con_iva' => 1695.0,
                        'costo_neto_sin_iva' => 1424.37,
                        'precio_afiliado_con_iva' => 1800.0,
                        'precio_afiliado_sin_iva' => 1512.61,
                        'ganancia_sin_iva' => 88.24,
                        'pct_ganancia' => 6.2,
                        'pct_desc_cliente_sin_iva' => 0.0,
                        'estado' => '',
                        'ultima_fecha' => '2026-09-10 08:30:00',
                    ]],
                ])
            ),
            new OA\Response(response: 401, description: 'Falta la DOLAPIKEY, o no es la de un acceso habilitado de ese proveedor'),
            new OA\Response(response: 429, description: 'Demasiados intentos'),
        ]
    )]
    public function productosProveedor(Request $request, int $idProveedor): JsonResponse
    {
        [$status, $body] = $this->integrosoneService->productosEventoProveedor(
            $request->header('DOLAPIKEY'),
            $idProveedor,
        );

        return response()->json($body, $status);
    }

    #[OA\Get(
        path: '/api/api.php/integrosone/proveedores/{idProveedor}/categorias',
        summary: 'Categorias de los productos con precio por evento de un proveedor (solo categorias, sin productos). Valida con la DOLAPIKEY del propio proveedor',
        security: [['DolApiKey' => []]],
        tags: ['Integrosone'],
        parameters: [
            new OA\Parameter(name: 'idProveedor', in: 'path', required: true, description: 'llx_societe.rowid del proveedor', schema: new OA\Schema(type: 'integer'), example: 45),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Categorias distintas ordenadas por nombre, con cuantos productos del proveedor tiene cada una (lista vacia si no hay)',
                content: new OA\JsonContent(example: [
                    'total' => 2,
                    'categorias' => [
                        ['id' => 12, 'nombre' => 'ABARROTES', 'total_productos' => 40],
                        ['id' => 15, 'nombre' => 'GRANOS', 'total_productos' => 7],
                    ],
                ])
            ),
            new OA\Response(response: 401, description: 'Falta la DOLAPIKEY, o no es la de un acceso habilitado de ese proveedor'),
            new OA\Response(response: 429, description: 'Demasiados intentos'),
        ]
    )]
    public function categoriasProveedor(Request $request, int $idProveedor): JsonResponse
    {
        [$status, $body] = $this->integrosoneService->categoriasEventoProveedor(
            $request->header('DOLAPIKEY'),
            $idProveedor,
        );

        return response()->json($body, $status);
    }

    #[OA\Post(
        path: '/api/api.php/integrosone/proveedores/{idProveedor}/preordenes',
        summary: 'Crea una pre-orden de venta (llx_integros_precommande) a nombre de customer_id. Valida con la DOLAPIKEY del propio proveedor',
        security: [['DolApiKey' => []]],
        tags: ['Integrosone'],
        parameters: [
            new OA\Parameter(name: 'idProveedor', in: 'path', required: true, description: 'llx_societe.rowid del proveedor autenticado (via DOLAPIKEY). Solo identifica al proveedor, no se usa como fk_soc de la pre-orden', schema: new OA\Schema(type: 'integer'), example: 239),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_reference', 'customer_id', 'lines'],
                properties: [
                    new OA\Property(property: 'order_reference', type: 'string', description: 'Referencia UNICA del pedido origen (max 255). Si ya existe responde 409 con existing_preorder_id', example: 'PRESTA-20260921-1'),
                    new OA\Property(property: 'origen_orden', type: 'string', description: 'Origen de la orden. Default "1"', example: '1'),
                    new OA\Property(property: 'customer_id', type: 'integer', description: 'Obligatorio. llx_societe.rowid del cliente final; la pre-orden queda a su nombre (fk_soc = customer_id). Debe existir, ser cliente (client=1/2/3) y estar activo', example: 12345),
                    new OA\Property(property: 'customer_email', type: 'string', description: 'Opcional. Si coincide con un contacto activo se guarda su contact_id', example: ''),
                    new OA\Property(property: 'customer_phone', type: 'string', description: 'Opcional, informativo (no se persiste todavia)', example: '3001234567'),
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
            new OA\Response(response: 200, description: 'Pre-orden creada en borrador. fk_user_author queda NULL (el acceso de proveedor no es un usuario de sistema)'),
            new OA\Response(response: 401, description: 'Falta/invalida la DOLAPIKEY del proveedor, o datos de la pre-orden invalidos'),
            new OA\Response(response: 409, description: 'order_reference ya existe (code DUPLICATE_ORDER_REFERENCE, existing_preorder_id)'),
            new OA\Response(response: 429, description: 'Demasiados intentos'),
        ]
    )]
    public function crearPreordenProveedor(Request $request, int $idProveedor): JsonResponse
    {
        [$status, $body] = $this->integrosoneService->crearPreordenProveedor(
            $request->header('DOLAPIKEY'),
            $idProveedor,
            $request,
        );

        return response()->json($body, $status);
    }

    #[OA\Get(
        path: '/api/api.php/integrosone/proveedores/{idProveedor}/preordenes/pdf',
        summary: 'PDF de una preorden creada por este proveedor (fk_proveedor guardado al crearla debe coincidir con idProveedor). URL abierta, sin DOLAPIKEY: quien tenga el link (idProveedor + ref) puede descargarlo. Responde el binario del PDF (Content-Type: application/pdf)',
        tags: ['Integrosone'],
        parameters: [
            new OA\Parameter(name: 'idProveedor', in: 'path', required: true, description: 'llx_societe.rowid del proveedor', schema: new OA\Schema(type: 'integer'), example: 239),
            new OA\Parameter(name: 'ref', in: 'query', required: true, description: 'Ref de la preorden (integros_precommande.ref), la que devuelve crearPreordenProveedor', schema: new OA\Schema(type: 'string'), example: 'PRE-2026-0117'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Binario del PDF (application/pdf)'),
            new OA\Response(
                response: 404,
                description: 'Falta ref, la preorden no existe, o no fue creada por este proveedor',
                content: new OA\JsonContent(example: ['success' => false, 'message' => 'Pre-orden no encontrada.'])
            ),
        ]
    )]
    public function preordenPdf(Request $request, int $idProveedor)
    {
        $ref = trim((string) $request->query('ref', ''));
        if ($ref === '') {
            return response()->json(['success' => false, 'message' => "El parámetro 'ref' es obligatorio."], 404);
        }

        $resultado = $this->preordenService->generarPdfPreorden($ref, $idProveedor);

        if (! $resultado['success']) {
            return response()->json($resultado, 404);
        }

        return response($resultado['contenido'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$resultado['filename'].'"',
            'Content-Length' => (string) strlen($resultado['contenido']),
        ]);
    }
}
