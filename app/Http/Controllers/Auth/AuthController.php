<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DolibarrUser;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService)
    {
    }

    /**
     * POST /api/auth/login
     * Body: { login, password, device_name, ip?, version? }
     */
    #[OA\Post(
        path: '/api/custom/integrospos/login.php',
        summary: 'Inicia sesion con las credenciales del usuario en Dolibarr y emite un access/refresh token',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['login', 'password', 'device_name'],
                properties: [
                    new OA\Property(property: 'login', type: 'string', example: 'Integros365'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'device_name', type: 'string', description: 'Identificador/nombre estable del dispositivo o terminal desde el que se inicia sesion. Es a la vez el "slot" del token (un token activo por device_name, se revoca el anterior con ese mismo nombre) y el identificador usado para bloquear sesiones concurrentes en otro dispositivo.', example: 'caja-1'),
                    new OA\Property(property: 'ip', type: 'string', description: 'IP del dispositivo POS. Opcional: si no se envia se guarda la IP de la peticion.', example: '192.168.1.20'),
                    new OA\Property(property: 'version', type: 'string', description: 'Version del POS. Opcional.', example: '2.4.1'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sesion emitida. Copia el access_token y pegalo en el boton Authorize (SanctumToken) para probar las rutas protegidas.',
                content: new OA\JsonContent(example: [
                    'access_token' => '9f1c2a7b3e4d5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a',
                    'refresh_token' => 'a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1',
                    'expires_at' => '2026-07-07 00:00:00',
                    'device_id' => 'caja-1',
                    'user_id' => '1',
                    'APIKEY' => 'GX8g3Y7N2vxv4KDc9Zlb43lNs0O9WknL',
                    'apertura' => true,
                    'cierre' => false,
                    'continuar' => true,
                ])
            ),
            new OA\Response(response: 400, description: 'Body vacio o con JSON invalido'),
            new OA\Response(response: 403, description: 'Parametros incompletos, credenciales invalidas, usuario inactivo, sin api_key o sin terminal configurada'),
            new OA\Response(response: 423, description: 'El usuario ya tiene una sesion activa en otro dispositivo (con distinto device_name)'),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        [$status, $body] = $this->authService->login($request);

        return response()->json($body, $status);
    }

    /**
     * POST /custom/integrospos/refresh
     * Body: { refresh_token }
     */
    #[OA\Post(
        path: '/api/custom/integrospos/refresh',
        summary: 'Renueva la sesion (rota access_token y refresh_token) a partir de un refresh_token valido',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Nueva sesion emitida (mismo formato que /login)'),
            new OA\Response(response: 401, description: 'Refresh token invalido o expirado'),
        ]
    )]
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $session = $this->authService->refresh($data['refresh_token']);

        if ($session === null) {
            return response()->json([
                'message' => 'Refresh token inválido o expirado.',
            ], 401);
        }

        return response()->json($session);
    }

    /**
     * GET /custom/integrospos/me  (requiere token)
     */
    #[OA\Get(
        path: '/api/custom/integrospos/me',
        summary: 'Datos del usuario autenticado (Dolibarr) segun el access_token enviado',
        security: [['SanctumToken' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Usuario de Dolibarr asociado al token'),
            new OA\Response(response: 401, description: 'Token invalido, expirado o ausente'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * POST /custom/integrospos/logout  (requiere DOLAPIKEY o access_token)
     */
    #[OA\Post(
        path: '/api/custom/integrospos/logout',
        summary: 'Cierra la sesion del usuario (revoca el access_token si vino uno, y cierra el registro de pos_login_logout)',
        security: [['SanctumToken' => []], ['DolApiKey' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sesion cerrada (revisar la presencia de "error": no significa que el token no se haya revocado, solo que no habia un registro de pos_login_logout abierto para este usuario)',
                content: new OA\JsonContent(example: [
                    'success' => true,
                    'user_id' => 1,
                    'date_logout' => '2026-07-08 15:45:00',
                ])
            ),
            new OA\Response(response: 401, description: 'Faltan credenciales: ni DOLAPIKEY ni access_token identifican a un usuario'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $token = $this->resolverTokenPorBearer($request);

        if ($token) {
            /** @var DolibarrUser $user */
            $user = $token->tokenable;
            $user->withAccessToken($token);

            return response()->json($this->authService->logout($user));
        }

        // El cliente del POS llama a este endpoint solo con DOLAPIKEY (igual
        // que el resto de la API), sin access_token de Sanctum: se resuelve
        // por ese header con el mismo guard 'apikey' que usan esas rutas.
        /** @var DolibarrUser|null $user */
        $user = Auth::guard('apikey')->user();

        if (!$user) {
            return response()->json(['message' => 'Falta DOLAPIKEY o el access_token es invalido.'], 401);
        }

        return response()->json($this->authService->logout($user));
    }

    /**
     * POST /api.php/integrospos/terminales/integrosTokenOpen  (requiere DOLAPIKEY)
     * Body: { idusuario, device_id, version, ip_local, servidor_local, fecha }
     */
    #[OA\Post(
        path: '/api/api.php/integrospos/terminales/integrosTokenOpen',
        summary: 'Actualiza IP local, version del POS y servidor local de la sesion activa del usuario en el dispositivo',
        security: [['DolApiKey' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['device_id'],
                properties: [
                    new OA\Property(property: 'idusuario', type: 'integer', description: 'Opcional; si viene debe coincidir con el usuario de la DOLAPIKEY.', example: 1),
                    new OA\Property(property: 'device_id', type: 'string', example: 'DESKTOP-A9BV1HL'),
                    new OA\Property(property: 'version', type: 'string', example: '4.4.81'),
                    new OA\Property(property: 'ip_local', type: 'string', example: '192.168.1.25'),
                    new OA\Property(property: 'servidor_local', type: 'string', example: 'localhost.localdomain'),
                    new OA\Property(property: 'fecha', type: 'string', description: 'Fecha del POS (informativa; last_seen_at se guarda con la hora del servidor).', example: '2026-09-24 09:40:00'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Sesion activa actualizada'),
            new OA\Response(response: 400, description: 'Falta device_id'),
            new OA\Response(response: 401, description: 'Falta DOLAPIKEY o es invalida'),
            new OA\Response(response: 403, description: 'idusuario no corresponde al usuario de la DOLAPIKEY'),
            new OA\Response(response: 404, description: 'No hay sesion activa del usuario en ese device_id'),
        ]
    )]
    public function tokenOpen(Request $request): JsonResponse
    {
        /** @var DolibarrUser|null $user */
        $user = Auth::guard('apikey')->user();

        if (!$user) {
            return response()->json(['message' => 'Falta DOLAPIKEY o es invalida.'], 401);
        }

        [$status, $body] = $this->authService->registrarActividadDispositivo($user, $request->all());

        return response()->json($body, $status);
    }

    /**
     * Resuelve el PersonalAccessToken del request, si vino uno. Esta ruta
     * quedo fuera del grupo 'auth:sanctum', asi que el token se busca
     * directo por hash en vez de depender del guard de Sanctum. Acepta el
     * access_token tanto por el header "Authorization: Bearer <token>" como
     * por el body/query (access_token).
     */
    private function resolverTokenPorBearer(Request $request): ?PersonalAccessToken
    {
        $plain = $request->bearerToken() ?: trim((string) $request->input('access_token', ''));

        if ($plain === '') {
            return null;
        }

        return PersonalAccessToken::where('token', hash('sha256', $plain))->first();
    }
}
