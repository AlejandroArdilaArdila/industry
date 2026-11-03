<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Services\Configuracion\ConfiguracionUsuarioService;
use App\Services\Integrosone\IntegrosoneService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ConfiguracionUsuarioController extends Controller
{
    public function __construct(
        private ConfiguracionUsuarioService $configuracionUsuarioService,
        private UsuariosService $usuariosService,
        private IntegrosoneService $integrosoneService,
    ) {
    }

    #[OA\Post(
        path: '/api/api.php/integrosone/configuracionUsuario',
        summary: 'Configuracion del usuario autenticado por DOLAPIKEY, modulo iapk: credenciales/api_key, permisos, setup del modulo y metodos de pago. Emite el resultado por WebSocket a los clientes conectados. Con DOLAPIKEY de un proveedor devuelve una version reducida (solo setup, sin usuarios/permisos/almacenes)',
        security: [['DolApiKey' => []]],
        tags: ['Configuracion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Usuario, permisos iapk, setup del modulo y metodos de pago',
                content: new OA\JsonContent(example: [
                    'ok' => true,
                    'total' => 1,
                    'usuarios' => [[
                        'rowid' => 20,
                        'login' => 'jperez',
                        'pass_crypted' => '$2y$10$...',
                        'api_key' => 'dolcrypt:AES-256-CTR:...',
                        'admin' => false,
                        'fullname' => 'Juan Pérez',
                        'pin_admin' => 1234,
                        'permisos' => ['lire' => true, 'creer' => false, 'facturarVencidas' => true],
                    ]],
                    'setup' => [
                        'APK_CATEGORIA_METODOS_PAGO' => '5',
                        'APK_FACTURA_PREDETERMINADA' => 'borrador',
                        'MAIN_INFO_SOCIETE_NOM' => 'Mi Empresa SAS',
                    ],
                    'metodos' => [['id' => 1, 'code' => 'LIQ', 'metodo' => 'Efectivo']],
                    'almacenes' => [['idAlmacen' => 1, 'descripcion' => 'Almacen Principal'], ['idAlmacen' => 2, 'descripcion' => 'Secundario']],
                ])
            ),
            new OA\Response(response: 401, description: 'DOLAPIKEY invalida'),
        ]
    )]
    public function obtener(Request $request)
    {
        $apiKey = $request->header('DOLAPIKEY');
        $usuario = $this->usuariosService->buscarPorApiKey($apiKey);

        if ($usuario) {
            return response()->json($this->configuracionUsuarioService->obtener($request, $usuario));
        }

        if ($this->integrosoneService->accesoProveedorValido($apiKey)) {
            return response()->json($this->configuracionUsuarioService->obtenerParaProveedor());
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
