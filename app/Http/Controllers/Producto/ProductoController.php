<?php

namespace App\Http\Controllers\Producto;

use App\Http\Controllers\Controller;
use App\Services\Producto\ProductoService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ProductoController extends Controller
{
    public function __construct(private ProductoService $productoService)
    {

    }

    #[OA\Post(
        path: '/api/api.php/integrospos/productopos/searchProducto',
        summary: 'Busca productos para el POS, con precios por nivel de cliente, impuestos extra y promociones aplicadas',
        tags: ['Productos'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'byRefODesc', type: 'string', description: 'Filtra por ref o label del producto (LIKE)', example: 'Coca Cola'),
                    new OA\Property(property: 'byCodigoBarras', type: 'string', description: 'Busca por ref exacta o codigo de barras exacto'),
                    new OA\Property(property: 'puntos', type: 'integer', enum: [0, 1], description: 'Si es 1, solo productos redimibles por puntos y el precio devuelto es en puntos'),
                    new OA\Property(property: 'clienteId', type: 'integer', description: 'Id del cliente (societe), usado para resolver su nivel de precio y promociones aplicables', example: 3),
                    new OA\Property(property: 'ventaSinStock', type: 'boolean', description: 'Si es true, incluye productos sin stock (por defecto solo se listan con stock>0 o tipo servicio)'),
                    new OA\Property(property: 'limit', type: 'integer', description: 'Limite de resultados'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de productos (vacio si no hay match, o si byCodigoBarras encuentra mas de 1 coincidencia)',
                content: new OA\JsonContent(example: [[
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
                    'urlImagen' => 'https://miapp.com/viewimage.php?modulepart=product&file=/45/coca400.jpg&publictakepos=1',
                    'ventaGramos' => false,
                    'stock' => [['idAlmacen' => 1, 'cantidad' => 80], ['idAlmacen' => 2, 'cantidad' => 40]],
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
                    'fk_plantilla' => 0,
                    'idcategorias' => [12],
                    'precio_compra' => 1800.0,
                    'proveedor' => ['id' => 0, 'nombre' => ''],
                    'pum' => 'PUM $6,25 Kg',
                    'baseUvt' => 0.0,
                    'retencionUvt' => 0.0,
                ]])
            ),
        ]
    )]
    public function listarProductosApp(Request $request)
    {
        return response()->json($this->productoService->listarApp($request));
    }

    #[OA\Get(
        path: '/api/api.php/integrosone/productos/clientes',
        summary: 'Productos asignados a un cliente (integros_producto_cliente). idcliente=0 trae todos los clientes con productos asignados',
        security: [['DolApiKey' => []]],
        tags: ['Productos'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'idcliente', type: 'integer', description: 'Id del cliente (societe.rowid). 0 o ausente = todos', example: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Con idcliente>0: productos de ese cliente. Con idcliente=0: clientes con productos asignados',
                content: new OA\JsonContent(example: [
                    'cliente_id' => 12,
                    'total' => 1,
                    'productos' => [[
                        'rowid' => 5,
                        'fk_soc' => 12,
                        'fk_product' => 45,
                        'referencia' => 'COCA400',
                        'nombre' => 'Coca Cola 400ml',
                        'barcode' => '7702090001234',
                        'cantidad_tipica' => 2.0,
                        'stock_actual' => 80.0,
                        'fecha_creacion' => '2026-07-01 10:00:00',
                    ]],
                    'ids' => [45],
                ])
            ),
        ]
    )]
    public function getClienteProductos(Request $request)
    {
        return response()->json($this->productoService->clienteProductos($request));
    }
}
