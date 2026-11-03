<?php

namespace App\Http\Controllers\Usuarios;

use App\Http\Controllers\Controller;
use App\Services\Usuarios\LogErrorEndpointService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LogErrorEndpointController extends Controller
{
    public function __construct(private LogErrorEndpointService $logErrorEndpointService) {}

    #[OA\Post(
        path: '/api/api.php/integrospos/logs/registrarErrorEndpoint',
        summary: 'Registra un error de API (status >= 400) reportado por el POS (LoggingClient)',
        security: [['DolApiKey' => []]],
        tags: ['Usuarios'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['metodo', 'url', 'status_code', 'fecha'],
                properties: [
                    new OA\Property(property: 'deviceid', type: 'string', description: 'HOSTNAME_IP de la terminal', example: 'DESKTOP-CAJA01_192.168.1.50'),
                    new OA\Property(property: 'fk_user', type: 'integer', nullable: true, description: 'Usuario logueado en el POS (puede venir null si no hay sesion)', example: 42),
                    new OA\Property(property: 'metodo', type: 'string', example: 'POST'),
                    new OA\Property(property: 'url', type: 'string', example: 'https://miserver.com/api.php/integrospos/pos/ticket/payment'),
                    new OA\Property(property: 'status_code', type: 'integer', description: 'Siempre >= 400', example: 500),
                    new OA\Property(property: 'tiempo_ms', type: 'integer', example: 842),
                    new OA\Property(property: 'request_body', type: 'string', description: 'Body enviado por el POS (JSON como texto)'),
                    new OA\Property(property: 'response_body', type: 'string', description: 'Body devuelto por el servidor (JSON como texto)'),
                    new OA\Property(property: 'fecha', type: 'string', description: 'Fecha/hora local del POS, ISO-8601', example: '2026-07-08T12:34:56.000'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Error registrado. El POS solo revisa el status HTTP (200/201 = sincronizado, cualquier otro = reintenta despues)'),
            new OA\Response(response: 401, description: "Falta 'metodo'/'url'/'fecha', o 'status_code' invalido (< 400)"),
        ]
    )]
    public function registrarErrorEndpoint(Request $request)
    {
        $this->logErrorEndpointService->registrar($request);

        return response()->json(['ok' => true], 201);
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/auditoria',
        summary: 'Registra un evento de auditoria generico subido desde la cola local de sincronizacion del POS (sync_service.dart)',
        security: [['DolApiKey' => []]],
        tags: ['Usuarios'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['modulo', 'accion', 'entidad_tipo', 'fecha'],
                properties: [
                    new OA\Property(property: 'id', type: 'integer', nullable: true, description: 'Id LOCAL de SQLite de la terminal (referencia externa; se repite entre terminales, nunca es el PK del servidor)', example: 42),
                    new OA\Property(property: 'fecha', type: 'string', description: 'Fecha/hora local del evento, ISO-8601', example: '2026-08-02T14:35:10.123456'),
                    new OA\Property(property: 'modulo', type: 'string', description: 'Lista abierta', example: 'cotizaciones'),
                    new OA\Property(property: 'accion', type: 'string', description: 'Lista abierta', example: 'agregar_producto'),
                    new OA\Property(property: 'usuario_id', type: 'integer', nullable: true, example: 7),
                    new OA\Property(property: 'usuario_nombre', type: 'string', nullable: true, example: 'jperez'),
                    new OA\Property(property: 'entidad_tipo', type: 'string', description: 'Lista abierta: cotizacion, pedido_en_curso, nota_credito, caja, etc.', example: 'cotizacion'),
                    new OA\Property(property: 'entidad_id', type: 'string', nullable: true, description: 'Referencia local (numero_local), no el id crudo de la fila', example: '7-CO-02082026143510-0042'),
                    new OA\Property(property: 'tipo_documento', type: 'string', nullable: true, example: '3'),
                    new OA\Property(property: 'datos_antes_json', type: 'string', nullable: true, description: 'Snapshot JSON como string; null si no aplica (login, apertura de caja, etc.)'),
                    new OA\Property(property: 'datos_despues_json', type: 'string', nullable: true, description: 'Snapshot JSON como string'),
                    new OA\Property(property: 'descripcion', type: 'string', nullable: true, example: 'ref=1234 "Coca-Cola" agregado, precio=3500 — guardado'),
                    new OA\Property(property: 'fk_caja_local', type: 'string', nullable: true, example: '7-CJ-02082026080000-0011'),
                    new OA\Property(property: 'total', type: 'number', nullable: true, example: 15000.0),
                    new OA\Property(property: 'subtotal', type: 'number', nullable: true, example: 12605.0),
                    new OA\Property(property: 'iva', type: 'number', nullable: true, example: 2395.0),
                    new OA\Property(property: 'items', type: 'integer', nullable: true, example: 3),
                    new OA\Property(property: 'sincronizado', type: 'integer', description: 'Bookkeeping interno del cliente; el servidor lo ignora por completo', example: 0),
                    new OA\Property(property: 'intentos', type: 'integer', description: 'Bookkeeping interno del cliente; el servidor lo ignora por completo', example: 0),
                    new OA\Property(property: 'ultimo_error', type: 'string', nullable: true, description: 'Bookkeeping interno del cliente; el servidor lo ignora por completo'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Evento registrado. El POS solo revisa el status HTTP (200/201 = sincronizado, no la reintenta mas; cualquier otro codigo = incrementa intentos y reintenta despues)',
                content: new OA\JsonContent(example: ['ok' => true, 'id' => 42])
            ),
            new OA\Response(response: 401, description: "Falta 'modulo'/'accion'/'entidad_tipo'/'fecha', o 'fecha' invalida"),
        ]
    )]
    public function registrarAuditoria(Request $request)
    {
        return response()->json($this->logErrorEndpointService->registrarAuditoria($request), 201);
    }
}
