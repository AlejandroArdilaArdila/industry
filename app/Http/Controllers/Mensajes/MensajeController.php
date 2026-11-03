<?php

namespace App\Http\Controllers\Mensajes;

use App\Http\Controllers\Controller;
use App\Services\Mensajes\MensajeService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MensajeController extends Controller
{
    public function __construct(private MensajeService $mensajeService)
    {
    }

    #[OA\Post(
        path: '/api/api.php/integrosone/mensajes',
        summary: 'Mensajes del chat de vendedores por usuario (emisor, receptor o difusion) y opcionalmente por fecha',
        security: [['DolApiKey' => []]],
        tags: ['Mensajes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user'],
                properties: [
                    new OA\Property(property: 'user', type: 'integer', description: 'Id del usuario, filtra como emisor o receptor', example: 5),
                    new OA\Property(property: 'fecha', type: 'string', description: 'Fecha sin hora (YYYY-MM-DD). Si no viene, retorna todos', example: '2026-07-16'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Mensajes ordenados por fecha ascendente',
                content: new OA\JsonContent(example: [[
                    'rowid' => 10,
                    'fk_user_from' => 5,
                    'fk_user_to' => 8,
                    'mensaje' => 'Hola, como vas con el pedido?',
                    'tipo' => 'mensaje',
                    'fecha' => '2026-07-16 10:23:45',
                    'leido' => false,
                    'nombre_from' => 'Juan Pérez',
                    'nombre_to' => 'Ana Gómez',
                ]])
            ),
            new OA\Response(response: 401, description: "Falta el campo 'user'"),
        ]
    )]
    public function getMensajes(Request $request)
    {
        return response()->json($this->mensajeService->getMensajes($request));
    }
}
