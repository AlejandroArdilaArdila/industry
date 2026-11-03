<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Services\Clientes\ClientesService;
use App\Services\Integrosone\IntegrosoneService;
use App\Services\Producto\ProductoService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ClientesController extends Controller
{
    public function __construct(
        private ClientesService $clientesService,
        private UsuariosService $usuariosService,
        private ProductoService $productoService,
        private IntegrosoneService $integrosoneService,
    ) {}

    #[OA\Post(
        path: '/api/api.php/integrospos/customers/clients',
        summary: 'Busca clientes (terceros) del POS, con ciudad, medio/termino de pago y puntos de fidelizacion',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'byNombre', type: 'string', description: 'Filtra por nombre (LIKE)', example: 'Juan Perez'),
                    new OA\Property(property: 'byId', type: 'integer', description: 'Filtra por rowid exacto (si se envia, ignora los demas filtros)', example: 3),
                    new OA\Property(property: 'byDocument', type: 'string', description: 'Filtra por documento exacto (siren o code_client segun configuracion)', example: '900123456'),
                    new OA\Property(property: 'orderBy', type: 'string', enum: ['rowid', 'nom', 'siren', 'zip', 'price_level', 'remise_client', 'phone', 'email']),
                    new OA\Property(property: 'orderType', type: 'string', enum: ['ASC', 'DESC']),
                    new OA\Property(property: 'limit', type: 'integer', description: 'Limite de resultados'),
                    new OA\Property(property: 'fk_vendedor', type: 'integer', description: 'Solo clientes asignados a este vendedor (societe_commerciaux)', example: 5),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de clientes',
                content: new OA\JsonContent(example: [[
                    'rowid' => 3,
                    'nom' => 'Cliente generico',
                    'typent' => 0,
                    'address' => 'Calle 1 # 2-3',
                    'phone' => 3001234567,
                    'email' => 'cliente@example.com',
                    'siren' => '900123456',
                    'zipcode' => 50001,
                    'fk_departement' => 5,
                    'iva' => 1,
                    'depto' => 'Antioquia',
                    'code' => '05001',
                    'code_depto' => '05',
                    'ciudad' => 'Medellin',
                    'ciuu' => '6202',
                    'agrupacion' => 302,
                    'clasificacion' => 'Responsable IVA',
                    'tipoDocumento' => '31',
                    'aplicaPuntos' => true,
                    'historicoPuntos' => 0,
                    'puntosRedimibles' => 0,
                    'proximosVencer' => 0,
                    'fechaProximosVencer' => '2026-07-31 23:59:59',
                    'descuentoGlobal' => 0.0,
                    'listaPrecios' => 0,
                    'medioPagoId' => 1,
                    'medioPago' => 'Efectivo',
                    'terminoPagoId' => null,
                    'terminoPago' => null,
                ]])
            ),
            new OA\Response(response: 401, description: "Falta DOLAPIKEY o el usuario no tiene el permiso 'backend' de integrospos"),
        ]
    )]
    public function listarClientesApp(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->clientesService->listarClientes($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/customers/createClientPos',
        summary: 'Crea un cliente (tercero) del POS',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'nombreCliente', type: 'string', example: 'Juan Perez'),
                    new OA\Property(property: 'documento', type: 'string', description: 'Numero de documento (siren); al crear debe ser unico', example: '900123456'),
                    new OA\Property(property: 'tipoDocumento', type: 'string', description: 'CC o NIT', example: 'CC'),
                    new OA\Property(property: 'tipoEmpresa', type: 'integer', description: 'fk_typent', example: 0),
                    new OA\Property(property: 'direccion', type: 'string', example: 'Calle 1 # 2-3'),
                    new OA\Property(property: 'correo', type: 'string', example: 'cliente@example.com'),
                    new OA\Property(property: 'telefono', type: 'string', example: '3001234567'),
                    new OA\Property(property: 'ciudad', type: 'string', description: 'nombre exacto en c_ciudades', example: 'Medellin'),
                    new OA\Property(property: 'codeCiudad', type: 'string', description: 'code de c_ciudades_fe', example: '05001'),
                    new OA\Property(property: 'departamento', type: 'string', description: 'nombre exacto en c_departements', example: 'Antioquia'),
                    new OA\Property(property: 'codeDepto', type: 'string', description: 'code de c_departements', example: '05'),
                    new OA\Property(property: 'zipcode', type: 'integer', description: 'zipcode', example: '50013'),
                    new OA\Property(property: 'clasificacionTributaria', type: 'string', description: 'Solo se usa al crear'),
                    new OA\Property(property: 'aplicaPuntos', type: 'boolean', description: 'Solo aplica si el modulo FIDELIZACION esta activo'),
                    new OA\Property(property: 'Responsableva', type: 'integer', description: 'tva_assuj', example: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cliente creado o editado',
                content: new OA\JsonContent(example: [
                    'status' => 1,
                    'msg' => 'Cliente creado correctamente',
                    'idClient' => 10,
                ])
            ),
            new OA\Response(response: 401, description: "Falta DOLAPIKEY, el usuario no tiene el permiso 'backend', el documento ya existe, el idCliente no existe o no esta activo, o los datos enviados son incorrectos"),
        ]
    )]
    #[OA\Post(
        path: '/api/api.php/integrospos/customers/updateClientPos',
        summary: 'Edita un cliente (tercero) del POS',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'idCliente', type: 'integer', description: 'Si se envia, edita ese cliente en vez de crear uno nuevo', example: 10),
                    new OA\Property(property: 'nombreCliente', type: 'string', example: 'Juan Perez'),
                    new OA\Property(property: 'documento', type: 'string', description: 'Numero de documento (siren); al crear debe ser unico', example: '900123456'),
                    new OA\Property(property: 'tipoDocumento', type: 'string', description: 'CC o NIT', example: 'CC'),
                    new OA\Property(property: 'tipoEmpresa', type: 'integer', description: 'fk_typent', example: 0),
                    new OA\Property(property: 'direccion', type: 'string', example: 'Calle 1 # 2-3'),
                    new OA\Property(property: 'correo', type: 'string', example: 'cliente@example.com'),
                    new OA\Property(property: 'telefono', type: 'string', example: '3001234567'),
                    new OA\Property(property: 'ciudad', type: 'string', description: 'nombre exacto en c_ciudades', example: 'Medellin'),
                    new OA\Property(property: 'codeCiudad', type: 'string', description: 'code de c_ciudades_fe', example: '05001'),
                    new OA\Property(property: 'codeDepto', type: 'string', description: 'code de c_departements', example: '05'),
                    new OA\Property(property: 'departamento', type: 'string', description: 'nombre exacto en c_departements', example: 'Antioquia'),
                    new OA\Property(property: 'zipcode', type: 'integer', description: 'zipcode', example: '50013'),
                    new OA\Property(property: 'clasificacionTributaria', type: 'string', description: 'Solo se usa al crear'),
                    new OA\Property(property: 'aplicaPuntos', type: 'boolean', description: 'Solo aplica si el modulo FIDELIZACION esta activo'),
                    new OA\Property(property: 'Responsableva', type: 'integer', description: 'tva_assuj', example: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cliente creado o editado',
                content: new OA\JsonContent(example: [
                    'status' => 1,
                    'msg' => 'Cliente creado correctamente',
                    'idClient' => 10,
                ])
            ),
            new OA\Response(response: 401, description: "Falta DOLAPIKEY, el usuario no tiene el permiso 'backend', el documento ya existe, el idCliente no existe o no esta activo, o los datos enviados son incorrectos"),
        ]
    )]
    public function guardarClientePos(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->clientesService->guardarCliente($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/client/pendingInvoices',
        summary: 'Cartera pendiente y cupo de credito de un cliente, buscado por numero de documento (siren)',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'documentoId', type: 'string', example: '222222222222'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cupo de credito del cliente y sus facturas con saldo pendiente',
                content: new OA\JsonContent(example: [
                    'status' => 200,
                    'cliente' => [
                        'rowid' => 3,
                        'nombre' => 'Cliente generico',
                        'direccion' => 'Calle 1 # 2-3',
                        'telefono' => '3001234567',
                        'cupo_autorizado' => 1000000.0,
                        'cupo_utilizado' => 250000.0,
                        'cupo_disponible' => 750000.0,
                        'diasPlazoCupo' => 30,
                    ],
                    'facturas_pendientes' => [[
                        'factura_id' => 45,
                        'ref' => 'FA2601-0001',
                        'total_factura' => 250000.0,
                        'abonos' => 0.0,
                        'saldo_pendiente' => 250000.0,
                        'fecha_factura' => '2026-07-01',
                        'fecha_vencimiento' => '2026-07-31',
                        'dias_vencidos' => -24,
                    ]],
                ])
            ),
            new OA\Response(response: 400, description: 'El parametro documentoId es requerido'),
            new OA\Response(response: 401, description: "Falta DOLAPIKEY o el usuario no tiene el permiso 'backend'"),
            new OA\Response(response: 404, description: 'No se encontro un cliente con ese documento'),
        ]
    )]
    public function getClientPendingInvoices(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->clientesService->obtenerFacturasPendientes($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/puntosfidelizacion/redimirPuntos',
        summary: 'Redime puntos de fidelizacion de un cliente, tomandolos de los lotes mas proximos a vencer primero',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'puntos', type: 'integer', description: 'Cantidad de puntos a redimir', example: 500),
                    new OA\Property(property: 'idCliente', type: 'integer', example: 3),
                    new OA\Property(property: 'idProducto', type: 'integer', description: 'Producto por el que se redimen los puntos', example: 45),
                    new OA\Property(property: 'idUser', type: 'integer', description: 'Usuario que registra la redencion', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultado de la redencion (revisar "status": 0 = redimido, 1 = no se pudo redimir)',
                content: new OA\JsonContent(example: [
                    'message' => '500 puntos redimidos',
                    'status' => 0,
                    'fecha' => '2026-07-08 3:45pm',
                ])
            ),
        ]
    )]
    public function redimirPuntos(Request $request)
    {
        return response()->json($this->clientesService->redimirPuntos($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/puntosfidelizacion/productoPuntos',
        summary: 'Datos minimos de un producto redimible por puntos, buscado por referencia o codigo de barras exacto',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'producto', type: 'string', description: 'Referencia o codigo de barras exacto', example: 'COCA400'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto encontrado',
                content: new OA\JsonContent(example: [
                    'existe' => true,
                    'label' => 'Coca Cola 400ml',
                    'rowid' => 45,
                    'price_min_ttc' => 2500,
                    'cantidad_puntos' => 100,
                ])
            ),
            new OA\Response(
                response: 401,
                description: 'No se encontro el producto',
                content: new OA\JsonContent(example: [
                    'existe' => false,
                    'label' => '',
                    'rowid' => 0,
                    'price_min_ttc' => 0,
                    'cantidad_puntos' => 0,
                ])
            ),
        ]
    )]
    public function productoPuntos(Request $request)
    {
        $resultado = $this->clientesService->buscarProductoPuntos($request);

        return response()->json($resultado, $resultado['existe'] ? 200 : 401);
    }

    #[OA\Get(
        path: '/api/api.php/integrosone/negociacion/proveedores',
        summary: 'Proveedores (terceros) activos, para el modulo de negociacion',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Filtra por nombre o NIT (LIKE)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Limite de resultados (default 100)', schema: new OA\Schema(type: 'integer', default: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Proveedores ordenados por nombre',
                content: new OA\JsonContent(example: [[
                    'id' => 12,
                    'nombre' => 'Distribuidora ABC S.A.S.',
                    'rif' => '900123456',
                    'telefono' => '3001234567',
                ]])
            ),
        ]
    )]
    public function getProveedores(Request $request)
    {
        return response()->json($this->clientesService->proveedores($request));
    }

    #[OA\Get(
        path: '/api/api.php/integrosone/app/clientesCompletos',
        summary: 'Ficha completa de clientes activos: datos fiscales/cupo/condiciones de pago, cartera vencida, contactos y ultimos 20 movimientos (propuestas+ordenes+facturas) por cliente. Con DOLAPIKEY de un vendedor (llx_user), solo los clientes asignados a ese vendedor; con DOLAPIKEY de un proveedor, todos los clientes activos',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Clientes con su ficha completa',
                content: new OA\JsonContent(example: [
                    'ok' => true,
                    'total' => 1,
                    'clientes' => [[
                        'rowid' => 196,
                        'nom' => 'AVICOLA SAN MARTIN S.A.',
                        'name_alias' => '',
                        'code_client' => 'CU2606-00064',
                        'documento' => '900011106',
                        'tipo_documento' => 'NIT',
                        'address' => 'VDA VARGAS GRANJA LOS GALPONES',
                        'zip' => '05264',
                        'town' => '56',
                        'phone' => '6044512',
                        'phone_mobile' => '',
                        'fax' => '',
                        'email' => 'compras@avicolasanmartin.com',
                        'url' => '',
                        'client' => 1,
                        'fournisseur' => 0,
                        'descuento' => 0,
                        'nivel_precio' => 0,
                        'cupo_limite' => 0,
                        'fk_cond_reglement' => 0,
                        'fk_mode_reglement' => 0,
                        'tva_assuj' => 1,
                        'note_private' => '',
                        'note_public' => '',
                        'status' => 1,
                        'clasificacion_tributaria' => '3',
                        'ciuu' => '0146',
                        'ciudad' => 'Bello',
                        'agrupacion' => 101,
                        'clasificacion' => 'No responsable IVA',
                        'fk_reteiva' => '',
                        'cupo_base' => 0,
                        'cupo_actual' => 0,
                        'dias_plazo_cupo' => 0,
                        'fk_depto' => '',
                        'aplica_puntos' => 0,
                        'por_actualizar' => 1,
                        'contactos' => [],
                        'movimientos' => [[
                            'rowid' => 115384,
                            'tipo' => 'factura',
                            'ref' => 'FEM1355',
                            'fecha' => '2026-07-15T12:00:00',
                            'total_ht' => 27641,
                            'total_ttc' => 29400,
                            'estado' => 1,
                            'estado_label' => 'Pendiente',
                            'vendedor' => 'Eurides Mapari',
                        ]],
                        'cartera_vencida' => [[
                            'rowid' => 97623,
                            'ref' => 'FEM124',
                            'fecha' => '2026-07-02',
                            'fecha_vencimiento' => '2026-07-03',
                            'total_ttc' => 116000,
                            'total_pagado' => 0,
                            'saldo' => 116000,
                            'dias_vencido' => 13,
                        ]],
                    ]],
                ])
            ),
            new OA\Response(response: 401, description: 'DOLAPIKEY invalida'),
        ]
    )]
    public function clientesCompletos(Request $request)
    {
        $apiKey = $request->header('DOLAPIKEY');
        $usuario = $this->usuariosService->buscarPorApiKey($apiKey);

        if (! $usuario && ! $this->integrosoneService->accesoProveedorValido($apiKey)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json($this->clientesService->clientesCompletos($usuario));
    }

    #[OA\Get(
        path: '/api/api.php/integrosone/productos/clientes/{id}',
        summary: 'Productos habituales de un cliente (replica de GET /api.php/integrosapk/productos/clientes/{id}, modulo integrosapk). Mismo dato que /integrosone/productos/clientes?idcliente={id}, pero con el id en la URL',
        security: [['DolApiKey' => []]],
        tags: ['Clientes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Id del cliente (fk_soc). 0 = todos los clientes con productos asignados', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Productos habituales del cliente',
                content: new OA\JsonContent(example: [
                    'cliente_id' => 27,
                    'total' => 1,
                    'productos' => [[
                        'rowid' => 10,
                        'fk_soc' => 27,
                        'fk_product' => 5869,
                        'referencia' => '012345',
                        'nombre' => '* ACCESORIO CEJAS',
                        'barcode' => '7701234567890',
                        'cantidad_tipica' => 1.0,
                        'stock_actual' => 12.0,
                        'fecha_creacion' => '2026-06-01 10:00:00',
                    ]],
                    'ids' => [5869],
                ])
            ),
        ]
    )]
    public function getProductosCliente(Request $request, int $id)
    {
        $request->merge(['idcliente' => $id]);

        return response()->json($this->productoService->clienteProductos($request));
    }
}
