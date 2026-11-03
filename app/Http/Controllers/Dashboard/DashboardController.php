<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
    ) {}

    #[OA\Get(
        path: '/api/api.php/informes/dashboarddinamico/dashboards',
        summary: 'Lista los dashboards visibles para el usuario (propios + públicos)',
        security: [['DolApiKey' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'user_id', in: 'query', required: true, description: 'Id del usuario', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de dashboards',
                content: new OA\JsonContent(example: [
                    'total' => 1,
                    'dashboards' => [
                        [
                            'rowid' => 3,
                            'nombre' => 'Ventas',
                            'descripcion' => 'Resumen de ventas del mes',
                            'color_tema' => '#6d28d9',
                            'icono' => 'fa-chart-line',
                            'es_publico' => 1,
                            'n_widgets' => 4,
                            'date_creation' => '2026-06-01 10:00:00',
                            'tms' => '2026-07-15 09:30:00',
                            'propia' => true,
                            'autor' => 'Juan Pérez',
                        ],
                    ],
                ])
            ),
        ]
    )]
    public function dashboards(Request $request)
    {
        $userId = (int) $request->query('user_id', 0);

        return response()->json($this->dashboardService->listarDashboards($userId));
    }

    #[OA\Get(
        path: '/api/api.php/informes/dashboarddinamico/dashboards/{id}',
        summary: 'Metadatos de un dashboard (definido pero sin uso actual en la App)',
        security: [['DolApiKey' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Id del dashboard', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Metadatos del dashboard',
                content: new OA\JsonContent(example: [
                    'rowid' => 3,
                    'nombre' => 'Ventas',
                    'descripcion' => 'Resumen de ventas del mes',
                    'color_tema' => '#6d28d9',
                    'icono' => 'fa-chart-line',
                    'es_publico' => 1,
                    'fk_user_creat' => 20,
                    'date_creation' => '2026-06-01 10:00:00',
                    'tms' => '2026-07-15 09:30:00',
                ])
            ),
            new OA\Response(response: 404, description: 'Dashboard no encontrado'),
        ]
    )]
    public function detalle(int $id)
    {
        return response()->json($this->dashboardService->obtenerDashboard($id));
    }

    #[OA\Post(
        path: '/api/api.php/informes/dashboarddinamico/dashboards/datos',
        summary: 'Datos de los widgets de un dashboard (id va en el body, no en la URL), con período opcional de comparación',
        security: [['DolApiKey' => []]],
        tags: ['Dashboard'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['id'],
                properties: [
                    new OA\Property(property: 'id', type: 'integer', description: 'Id del dashboard (elegido en el listado)', example: 3),
                    new OA\Property(property: 'user_id', type: 'integer', example: 20),
                    new OA\Property(property: 'fecha_desde', type: 'string', example: '2026-07-01'),
                    new OA\Property(property: 'fecha_hasta', type: 'string', example: '2026-07-31'),
                    new OA\Property(property: 'limit', type: 'integer', example: 500),
                    new OA\Property(property: 'fecha_desde_comp', type: 'string', nullable: true, description: 'Solo si el usuario activó "comparar"', example: '2026-06-01'),
                    new OA\Property(property: 'fecha_hasta_comp', type: 'string', nullable: true, example: '2026-06-30'),
                    new OA\Property(property: 'widget_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Subconjunto de widgets a traer; vacío/omitido = todos', example: [1, 2, 3]),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Datos de cada widget del dashboard',
                content: new OA\JsonContent(example: [
                    'dashboard' => ['rowid' => 3, 'nombre' => 'Ventas', 'descripcion' => '', 'color_tema' => '#6d28d9', 'icono' => 'fa-chart-line'],
                    'tabs' => [
                        ['orden' => 0, 'nombre' => 'Ventas', 'total_widgets' => 5],
                        ['orden' => 1, 'nombre' => 'Clientes', 'total_widgets' => 4],
                    ],
                    'fecha_desde' => '2026-07-01',
                    'fecha_hasta' => '2026-07-31',
                    'fecha_desde_comp' => '2026-06-01',
                    'fecha_hasta_comp' => '2026-06-30',
                    'widgets' => [
                        [
                            'rowid' => 1,
                            'tab_nombre' => 'Ventas',
                            'tab_orden' => 0,
                            'posicion' => 0,
                            'ancho' => 6,
                            'alto' => 340,
                            'tipo_grafica' => 'column',
                            'titulo' => 'Ventas por día',
                            'config' => [],
                            'datos' => ['columnas' => ['fecha', 'total'], 'filas' => [['fecha' => '2026-07-05', 'total' => 152300.0]], 'total' => 1, 'sql_final' => 'SELECT ...'],
                            'comparacion' => ['columnas' => ['fecha', 'total'], 'filas' => [], 'total' => 0, 'sql_final' => 'SELECT ...'],
                        ],
                    ],
                ])
            ),
            new OA\Response(response: 400, description: "Falta 'id'"),
            new OA\Response(response: 403, description: 'Sin acceso a este dashboard'),
            new OA\Response(response: 404, description: 'Dashboard no encontrado'),
        ]
    )]
    public function datos(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            abort(response()->json(['error' => "El campo 'id' es obligatorio."], 400));
        }

        return response()->json($this->dashboardService->datosDashboard($id, $request));
    }
}
