<?php

namespace App\Http\Controllers\Productos;

use App\Http\Controllers\Controller;
use App\Services\Productos\ProductosService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ProductosController extends Controller
{
    public function __construct(
        private ProductosService $productosService,
        private UsuariosService $usuariosService,
    ) {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/products/getProductosByPromo/{idPromo}',
        summary: 'Productos de una promocion en formato POS completo (precios, stock, lotes, descuentos)',
        security: [['DolApiKey' => []]],
        tags: ['Productos'],
        parameters: [
            new OA\Parameter(
                name: 'idPromo',
                in: 'path',
                required: true,
                description: 'Id de la promocion',
                schema: new OA\Schema(type: 'integer'),
                example: 12
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'clienteId', type: 'integer', description: 'Id del cliente, para resolver su nivel de precios', example: 3),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Productos de la promocion y los clientes a los que aplica',
                content: new OA\JsonContent(example: [
                    'productos' => [[
                        'rowid' => 45,
                        'ref' => 'COCA400',
                        'label' => 'Coca Cola 400ml',
                        'description' => 'Bebida gaseosa',
                        'precio' => 2500,
                        'iva' => 475,
                        'porcentajeIva' => 19,
                        'impuestos' => 0,
                        'precio_total' => 3000,
                        'precio_total_sin_promo' => 3000,
                        'precio_minimo' => 2500,
                        'descuento' => 0,
                        'cantidad_descuento' => 0,
                        'urlImagen' => '',
                        'ventaGramos' => false,
                        'stock' => 120,
                        'codigo_barras' => '7702090001234',
                        'lotes' => [],
                        'ventaPrecio' => false,
                        'ventaCantidad' => false,
                        'impuestosExtras' => [],
                        'nivelesPrecios' => [],
                        'obsequio' => [],
                        'descuentoGlobal' => 0,
                        'tipoProducto' => 0,
                        'packs' => [],
                    ]],
                    'clientes_promocion' => ['promocion' => 12, 'clientes' => [3, 8]],
                    'totalEncontrados' => 1,
                    'totalNoEncontrados' => 0,
                    'idsNoEncontrados' => [],
                ])
            ),
            new OA\Response(response: 401, description: "El usuario no tiene el permiso 'backend' de integrospos"),
        ]
    )]
    public function getProductosByPromo(Request $request, int $idPromo)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->productosService->productosPorPromocion($request, $idPromo, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/products/postCreateProduct',
        summary: 'Crea o edita un producto del POS: si el payload trae idProducto lo edita, si no lo trae crea uno nuevo',
        security: [['DolApiKey' => []]],
        tags: ['Productos'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'ref', type: 'string', description: 'Referencia; al crear debe ser unica', example: 'COCA400'),
                    new OA\Property(property: 'etiqueta', type: 'string', description: 'Nombre/label del producto', example: 'Coca Cola 400ml'),
                    new OA\Property(property: 'descripcion', type: 'string', example: 'Bebida gaseosa'),
                    new OA\Property(property: 'estadoVenta', type: 'integer', enum: [0, 1]),
                    new OA\Property(property: 'estadoCompra', type: 'integer', enum: [0, 1]),
                    new OA\Property(property: 'precioConIva', type: 'number', example: 3000),
                    new OA\Property(property: 'precioMin', type: 'number', example: 2800),
                    new OA\Property(property: 'iva', type: 'number', description: 'Tasa de IVA', example: 19),
                    new OA\Property(property: 'codigoBarras', type: 'array', items: new OA\Items(type: 'string'), example: ['7702090001234']),
                    new OA\Property(property: 'stock', type: 'number', description: 'Solo se usa al crear', example: 100),
                    new OA\Property(property: 'lote', type: 'string', description: 'Solo se usa al crear, junto con stock'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto creado o editado',
                content: new OA\JsonContent(example: [
                    'status' => 1,
                    'msg' => 'Producto agregado correctamente',
                    'idProduct' => 45,
                ])
            ),
            new OA\Response(response: 401, description: 'Falta DOLAPIKEY, faltan campos obligatorios, la referencia/codigo de barras ya existen, el idProducto no existe, o la terminal no tiene permiso para crear/editar precios'),
        ]
    )]
    public function createProductPos(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->productosService->guardarProducto($request, $usuario));
    }
}
