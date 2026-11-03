<?php

namespace App\Http\Controllers\Usuarios;

use App\Http\Controllers\Controller;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class UsuariosController extends Controller
{
    public function __construct(private UsuariosService $usuariosService)
    {

    }

    #[OA\Post(
        path: '/api/api.php/integrospos/user/getUsersPos',
        summary: 'Lista los usuarios POS activos con sus permisos, terminal, almacen, cliente predeterminado y estado de caja',
        tags: ['Usuarios'],
        parameters: [
            new OA\Parameter(
                name: 'idUsuario',
                description: 'Si se envia (> 0), filtra y devuelve solo ese usuario en vez de todos los activos',
                in: 'query',
                schema: new OA\Schema(type: 'integer'),
                example: 15
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de usuarios activos',
                content: new OA\JsonContent(example: [[
                    'rowid' => 1,
                    'login' => 'a.ardila',
                    'pass_crypted' => '$2y$10$...',
                    'api_key' => 'dolcrypt:AES-256-CTR:10fbb3f05469219f:...',
                    'pin_admin' => 0,
                    'admin' => false,
                    'fullname' => 'Andres Ardila',
                    'idVendedor' => 1,
                    'codigoVendedor' => '1',
                    'nombreVendedor' => 'Andres Ardila',
                    'idTerminal' => 2,
                    'idAlmacen' => 1,
                    'idCliente' => 3,
                    'documentoCliente' => 900123456,
                    'nombreCliente' => 'Cliente generico',
                    'cierreCaja' => false,
                    'aperturaCaja' => true,
                    'reAperturaCaja' => false,
                    'datafonoTerminal' => [
                        'codigoDeTerminal' => '', 'idCajero' => '', 'numeroDeCaja' => '',
                    ],
                    'permisos' => [
                        'pinCajon' => false,
                        'pinRimprimir' => false,
                        'pinDevoluciones' => false,
                        'pinSumarRestar' => false,
                        'pinEliminar' => false,
                        'pedidosProvisionales' => false,
                        'aplicaDevoluciones' => true,
                        'nivelPrecioPinUsuario' => false,
                        'devoluciones' => true,
                        'entradasSalidas' => false,
                        'eliminarCotizacion' => false,
                        'descuentoGlobal' => false,
                        'abonoFacturas' => false,
                        'redencionPuntos' => true,
                        'aperturaCaja' => true,
                        'aperturas' => true,
                        'backend' => true,
                        'crearCierres' => false,
                        'verCierres' => true,
                        'domicilios' => false,
                        'domiciliosAsignados' => false,
                        'exportarEntradasSalidas' => false,
                        'facturas' => true,
                        'frontend' => true,
                        'impresionF8' => false,
                        'informeVentas' => false,
                        'logUsuarios' => false,
                        'pinDescuentoGlobal' => false,
                        'pinEliminarCotizacion' => false,
                        'productosRedimidos' => false,
                        'promo' => false,
                        'terminal' => false,
                        'ticketVariables' => false,
                    ],
                ]])
            ),
        ]
    )]
    public function listarUsuariosApp(Request $request)
    {
        return response()->json($this->usuariosService->getUsersPos($request));
    }

    public function listarUsuariosONE(Request $request)
    {
        return response()->json($this->usuariosService->getUsersONE($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/autorizacion',
        summary: 'Valida un PIN de administrador y devuelve a que usuario pertenece',
        security: [['DolApiKey' => []]],
        tags: ['Usuarios'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['pin'],
                properties: [
                    new OA\Property(property: 'pin', type: 'string', description: 'Pin a validar (user_extrafields.pin_admin)', example: '1234'),
                    new OA\Property(property: 'user', type: 'integer', description: 'Id del usuario actualmente en sesion en el POS, para saber si el pin es de el mismo', example: 5),
                    new OA\Property(property: 'bandera', description: 'Si se envia (cualquier valor), un pin no encontrado responde 200 con status=1 en vez de un 401', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Resultado de la validacion: 'Pin valido', 'Pin valido para este usuario', o (solo si se envio 'bandera') 'Pin no encontrado'",
                content: new OA\JsonContent(example: [
                    'message' => 'Pin valido',
                    'nombre' => 'Andres Ardila',
                    'status' => 0,
                ])
            ),
            new OA\Response(response: 401, description: "Falta DOLAPIKEY, el usuario no tiene el permiso 'backend' de integrospos, o el pin no existe (y no se envio 'bandera')"),
        ]
    )]
    public function autorizacion(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->usuariosService->autorizarPin($request, $usuario));
    }

    #[OA\Post(
        path: '/api/custom/integrospos/validateApiKey',
        summary: 'Valida una APIKEY de Dolibarr (endpoint publico; la APIKEY va en el header DOLAPIKEY, no en el body)',
        security: [['DolApiKey' => []]],
        tags: ['Usuarios'],
        responses: [
            new OA\Response(
                response: 200,
                description: "Siempre 200 (nunca aborta); 'ok' indica si la APIKEY es valida",
                content: new OA\JsonContent(example: [
                    'ok' => true,
                    'user' => ['id' => 5, 'login' => 'a.ardila', 'nombre' => 'Andres Ardila', 'admin' => 1],
                ])
            ),
        ]
    )]
    public function validateApiKey(Request $request)
    {
        return response()->json($this->usuariosService->validarApiKey((string) $request->header('DOLAPIKEY', '')));
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/pos/ping',
        summary: 'Healthcheck liviano: responde 200 de inmediato, sin tocar sesion/DB',
        tags: ['Usuarios'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Siempre 200 OK',
                content: new OA\JsonContent(example: ['ok' => true])
            ),
        ]
    )]
    public function ping()
    {
        return response()->json(['ok' => true]);
    }
}
