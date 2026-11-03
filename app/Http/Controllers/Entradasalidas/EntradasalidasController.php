<?php

namespace App\Http\Controllers\Entradasalidas;

use App\Http\Controllers\Controller;
use App\Services\Entradasalidas\EntradasalidasService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class EntradasalidasController extends Controller
{
    public function __construct(
        private EntradasalidasService $entradasalidasService,
        private UsuariosService $usuariosService,
    ) {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/entradasalidas/serviciosCorresponsal',
        summary: 'Productos configurados como servicio de corresponsal bancario (consignacion/retiro), agrupados por tipo',
        tags: ['Entradasalidas'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Productos agrupados por tipo de servicio de corresponsal',
                content: new OA\JsonContent(example: [
                    'status' => 0,
                    'consignacion' => [
                        ['rowid' => 10, 'ref' => 'CONS-NEQUI', 'label' => 'Consignación Nequi'],
                    ],
                    'retiro' => [
                        ['rowid' => 11, 'ref' => 'RET-NEQUI', 'label' => 'Retiro Nequi'],
                    ],
                    'mal_configurado' => [],
                ])
            ),
        ]
    )]
    public function serviciosCorresponsal()
    {
        return response()->json($this->entradasalidasService->serviciosCorresponsal());
    }

    public function createApp(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->entradasalidasService->createApp($request, $usuario));
    }

    public function crearReciboCaja(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->entradasalidasService->crearReciboCaja($request, $usuario));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/pos/backup/entradaSalida',
        summary: 'Respaldo/bitacora de una entrada/salida de caja confirmada en el POS offline (Flutter) - llamada adicional, en paralelo al sync de negocio de entradasalidas/create',
        security: [['DolApiKey' => []]],
        tags: ['Backup'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refLocal'],
                properties: [
                    new OA\Property(property: 'refLocal', type: 'string', example: 'ES-...'),
                    new OA\Property(property: 'monto', type: 'number', example: 50000),
                    new OA\Property(property: 'tipo', type: 'integer', example: 1),
                    new OA\Property(property: 'fkUsuario', type: 'integer', example: 7),
                    new OA\Property(property: 'fkClient', type: 'integer', example: 123),
                    new OA\Property(property: 'nombreCliente', type: 'string', example: 'Consumidor Final'),
                    new OA\Property(property: 'descripcion', type: 'string', example: 'Retiro para banco'),
                    new OA\Property(property: 'facturasAfectadas', type: 'array', items: new OA\Items()),
                    new OA\Property(property: 'fecha', type: 'string', example: '2026-07-10 14:32:10'),
                    new OA\Property(property: 'idServicio', type: 'integer', nullable: true),
                    new OA\Property(property: 'metodoPago', type: 'array', items: new OA\Items()),
                    new OA\Property(property: 'datosServer', type: 'object', description: 'datos_server_json si ya sincronizo, o null'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Guardado (upsert por refLocal)', content: new OA\JsonContent(example: ['ok' => true])),
            new OA\Response(response: 401, description: "Falta 'refLocal'"),
        ]
    )]
    public function backupEntradaSalida(Request $request)
    {
        return response()->json($this->entradasalidasService->backupEntradaSalida($request));
    }
}
