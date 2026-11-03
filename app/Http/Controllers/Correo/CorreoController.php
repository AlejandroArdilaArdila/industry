<?php

namespace App\Http\Controllers\Correo;

use App\Http\Controllers\Controller;
use App\Services\Correo\CorreoService;
use App\Services\Integrosone\IntegrosoneService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class CorreoController extends Controller
{
    public function __construct(
        private CorreoService $correoService,
        private UsuariosService $usuariosService,
        private IntegrosoneService $integrosoneService,
    ) {}

    #[OA\Post(
        path: '/api/api.php/integrosapk/app/enviarMensajePedido',
        summary: 'Envia un correo electronico al cliente (confirmacion de pedido, factura, etc.) usando el SMTP configurado en Dolibarr. Si enviar_pdf=1 e id_factura>0 adjunta el PDF de la factura, si ya existe generado',
        security: [['DolApiKey' => []]],
        tags: ['Correo'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['id_cliente', 'asunto', 'cuerpo'],
                properties: [
                    new OA\Property(property: 'id_usuario', type: 'integer', description: 'Id del vendedor que envia (informativo, no se usa para autenticar)', example: 5),
                    new OA\Property(property: 'id_cliente', type: 'integer', description: 'llx_societe.rowid del cliente destinatario', example: 196),
                    new OA\Property(property: 'id_factura', type: 'integer', description: 'llx_facture.rowid; requerido solo si enviar_pdf=1', example: 0),
                    new OA\Property(property: 'enviar_pdf', type: 'boolean', description: 'Si es true adjunta el PDF de la factura (si ya existe generado en disco)', example: false),
                    new OA\Property(property: 'email', type: 'string', description: 'Correo(s) destino separados por ";". Si se omite, usa el email del cliente en BD', example: 'compras@cliente.com'),
                    new OA\Property(property: 'asunto', type: 'string', example: 'Confirmación de tu pedido'),
                    new OA\Property(property: 'cuerpo', type: 'string', description: 'Cuerpo HTML del correo, ya con las variables reemplazadas', example: '<p>Tu pedido fue recibido correctamente.</p>'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Correo enviado',
                content: new OA\JsonContent(example: [
                    'ok' => true,
                    'to' => 'compras@cliente.com',
                    'cliente' => 'AVICOLA SAN MARTIN S.A.',
                    'adjunto_pdf' => false,
                ])
            ),
            new OA\Response(response: 401, description: 'DOLAPIKEY invalida, o datos/cliente/remitente invalidos'),
            new OA\Response(response: 422, description: 'Faltan parametros'),
        ]
    )]
    public function enviarMensajePedido(Request $request)
    {
        $apiKey = $request->header('DOLAPIKEY');
        $usuario = $this->usuariosService->buscarPorApiKey($apiKey);

        if (! $usuario && ! $this->integrosoneService->accesoProveedorValido($apiKey)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'id_cliente' => ['required', 'integer'],
            'id_factura' => ['nullable', 'integer'],
            'enviar_pdf' => ['nullable', 'boolean'],
            'email' => ['nullable', 'string', 'max:255'],
            'asunto' => ['required', 'string', 'max:255'],
            'cuerpo' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Parámetros inválidos.', 'errors' => $validator->errors()], 422);
        }

        $resultado = $this->correoService->enviarMensajePedido(
            (int) $request->input('id_cliente'),
            (int) $request->input('id_factura', 0),
            (bool) $request->input('enviar_pdf', false),
            $request->input('email'),
            $request->input('asunto'),
            $request->input('cuerpo'),
        );

        $status = $resultado['ok'] ? 200 : ($resultado['status'] ?? 401);
        unset($resultado['status']);

        return response()->json($resultado, $status);
    }
}
