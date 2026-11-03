<?php

namespace App\Http\Controllers\InformeDinamico;

use App\Http\Controllers\Controller;
use App\Services\InformeDinamico\InformeDinamicoService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class InformeDinamicoController extends Controller
{
    public function __construct(
        private InformeDinamicoService $informeDinamicoService,
    ) {}

    #[OA\Get(
        path: '/api/api.php/integrospos/consultadinamica/consultas',
        summary: 'Lista las consultas dinámicas marcadas para uso en App (para_app != "")',
        security: [['DolApiKey' => []]],
        tags: ['InformeDinamico'],
        parameters: [
            new OA\Parameter(
                name: 'app',
                in: 'query',
                required: false,
                description: "Filtrar por destino: 'integros', 'bingo' o 'ambas'. Vacío = todas.",
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de consultas disponibles',
                content: new OA\JsonContent(example: [
                    'total' => 1,
                    'consultas' => [
                        [
                            'rowid' => 12,
                            'nombre' => 'Ventas por vendedor',
                            'descripcion' => 'Totales de venta agrupados por vendedor',
                            'para_app' => 'integros',
                            'tipo' => 'dinamica',
                            'columnas' => [['nombre' => 'vendedor', 'formato' => '', 'ancho' => 0, 'fija' => '']],
                            'entradas' => [
                                ['indice' => 0, 'placeholder' => ':fecha_inicio', 'titulo' => 'Fecha inicio', 'tipo' => 'fecha', 'campo' => 'llx_facture.datef', 'operador' => '>=', 'valor_default' => '', 'opciones' => null],
                            ],
                            'sql_plantilla' => 'SELECT ...',
                            'sql_error' => null,
                        ],
                    ],
                ])
            ),
        ]
    )]
    public function consultas(Request $request)
    {
        return response()->json($this->informeDinamicoService->listarConsultas($request->query('app')));
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/consultadinamica/consultas/{rowid}',
        summary: 'Detalle de una consulta dinámica: columnas y parámetros de entrada ("entradas")',
        security: [['DolApiKey' => []]],
        tags: ['InformeDinamico'],
        parameters: [
            new OA\Parameter(name: 'rowid', in: 'path', required: true, description: 'Id de la consulta', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Consulta con sus entradas',
                content: new OA\JsonContent(example: [
                    'rowid' => 12,
                    'nombre' => 'Ventas por vendedor',
                    'descripcion' => 'Totales de venta agrupados por vendedor',
                    'para_app' => 'integros',
                    'tipo' => 'dinamica',
                    'columnas' => [['nombre' => 'vendedor', 'formato' => '', 'ancho' => 0, 'fija' => '']],
                    'entradas' => [
                        ['indice' => 0, 'placeholder' => ':fecha_inicio', 'titulo' => 'Fecha inicio', 'tipo' => 'fecha', 'requerido' => 1, 'campo' => 'llx_facture.datef', 'operador' => '>=', 'valor_default' => '', 'opciones' => null],
                    ],
                    'sql_plantilla' => 'SELECT ...',
                    'sql_error' => null,
                ])
            ),
            new OA\Response(response: 404, description: 'Consulta no encontrada'),
            new OA\Response(response: 403, description: 'La consulta no está marcada para uso en App'),
        ]
    )]
    public function consulta(int $rowid)
    {
        return response()->json($this->informeDinamicoService->obtenerConsulta($rowid));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/consultadinamica/consultas/{rowid}/ejecutar',
        summary: 'Ejecuta una consulta dinámica con los valores de entrada, paginación, orden y búsqueda',
        security: [['DolApiKey' => []]],
        tags: ['InformeDinamico'],
        parameters: [
            new OA\Parameter(name: 'rowid', in: 'path', required: true, description: 'Id de la consulta', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'entradas', type: 'object', description: 'Mapa placeholder (sin ":") → valor', example: ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31']),
                    new OA\Property(property: 'limit', type: 'integer', example: 10),
                    new OA\Property(property: 'offset', type: 'integer', example: 0),
                    new OA\Property(property: 'order_by', type: 'string', example: 'fecha'),
                    new OA\Property(property: 'order_dir', type: 'string', example: 'ASC'),
                    new OA\Property(property: 'search', type: 'string', example: 'texto opcional'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultado paginado de la consulta',
                content: new OA\JsonContent(example: [
                    'consulta' => ['rowid' => 12, 'nombre' => 'Ventas por vendedor'],
                    'columnas' => [['nombre' => 'vendedor', 'formato' => '', 'ancho' => 0, 'fija' => '']],
                    'total' => 137,
                    'filas' => [['vendedor' => 'jperez', 'total' => 152300.0]],
                ])
            ),
            new OA\Response(response: 400, description: 'Payload/SQL inválido'),
            new OA\Response(response: 404, description: 'Consulta no encontrada'),
        ]
    )]
    public function ejecutar(int $rowid, Request $request)
    {
        return response()->json($this->informeDinamicoService->ejecutarConsulta($rowid, $request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/consultadinamica/consultas/ejecutar',
        summary: 'Igual que POST .../consultas/{rowid}/ejecutar, pero recibiendo "rowid" en el body en vez de en la URL',
        security: [['DolApiKey' => []]],
        tags: ['InformeDinamico'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rowid'],
                properties: [
                    new OA\Property(property: 'rowid', type: 'integer', description: 'Id de la consulta', example: 25),
                    new OA\Property(property: 'entradas', type: 'object', description: 'Mapa placeholder (sin ":") → valor', example: ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31']),
                    new OA\Property(property: 'limit', type: 'integer', example: 10),
                    new OA\Property(property: 'offset', type: 'integer', example: 0),
                    new OA\Property(property: 'order_by', type: 'string', example: 'fecha'),
                    new OA\Property(property: 'order_dir', type: 'string', example: 'ASC'),
                    new OA\Property(property: 'search', type: 'string', example: ''),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultado paginado de la consulta',
                content: new OA\JsonContent(example: [
                    'consulta' => ['rowid' => 25, 'nombre' => 'Ventas por vendedor'],
                    'columnas' => [['nombre' => 'fecha', 'formato' => '', 'ancho' => 0, 'fija' => '']],
                    'total' => 137,
                    'filas' => [['fecha' => '2026-07-05', 'total' => 152300.0]],
                ])
            ),
            new OA\Response(response: 400, description: 'Payload/SQL inválido, o falta "rowid"'),
            new OA\Response(response: 404, description: 'Consulta no encontrada'),
        ]
    )]
    public function ejecutarPorBody(Request $request)
    {
        $rowid = (int) $request->input('rowid', 0);

        if ($rowid <= 0) {
            abort(response()->json(['error' => "El campo 'rowid' es obligatorio."], 400));
        }

        return response()->json($this->informeDinamicoService->ejecutarConsulta($rowid, $request));
    }
}
