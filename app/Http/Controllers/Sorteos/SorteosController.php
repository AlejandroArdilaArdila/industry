<?php

namespace App\Http\Controllers\Sorteos;

use App\Http\Controllers\Controller;
use App\Services\Sorteos\SorteosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SorteosController extends Controller
{
    public function __construct(private SorteosService $sorteosService)
    {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/sorteos/getSorteos',
        summary: 'Listado paginado de sorteos (grilla estilo Syncfusion DataManager)',
        tags: ['Sorteos'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'skip', type: 'integer', example: 0),
                    new OA\Property(property: 'take', type: 'integer', example: 50),
                    new OA\Property(
                        property: 'sorted',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'fecha_inicio'),
                            new OA\Property(property: 'direction', type: 'string', enum: ['ascending', 'descending']),
                        ])
                    ),
                    new OA\Property(
                        property: 'where',
                        type: 'array',
                        description: 'Predicados estilo Syncfusion DataManager (field/operator/value, agrupables con isComplex+predicates)',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'field', type: 'string', example: 'descripcion'),
                            new OA\Property(property: 'operator', type: 'string', enum: ['startswith', 'endswith', 'contains', 'equal', 'notequal', 'greaterthan', 'greaterthanorequal', 'lessthan', 'lessthanorequal']),
                            new OA\Property(property: 'value', type: 'string', example: 'Navidad'),
                        ])
                    ),
                    new OA\Property(property: 'fecha_desde', type: 'string', format: 'date', description: 'Filtra fecha_inicio >= valor'),
                    new OA\Property(property: 'fecha_hasta', type: 'string', format: 'date', description: 'Filtra fecha_inicio <= valor'),
                    new OA\Property(property: 'fecha_final_desde', type: 'string', format: 'date', description: 'Filtra fecha_final >= valor'),
                    new OA\Property(property: 'fecha_final_hasta', type: 'string', format: 'date', description: 'Filtra fecha_final <= valor'),
                    new OA\Property(property: 'estado_filtro', type: 'string', enum: ['', '1', '0'], description: "'' = todos, '1' = activos, '0' = inactivos"),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Filas paginadas y el total de registros que cumplen los filtros',
                content: new OA\JsonContent(example: [
                    'rowData' => [[
                        'rowid' => 1,
                        'descripcion' => 'Sorteo Navidad',
                        'premio' => 'Televisor 50 pulgadas',
                        'fecha_inicio' => '01/12/2026',
                        'fecha_final' => '24/12/2026',
                        'fecha_inicio_raw' => '2026-12-01',
                        'fecha_final_raw' => '2026-12-24',
                        'compra_minimo' => 50000,
                        'valor_participacion' => 1,
                        'tipo_cliente' => 0,
                        'tipo_calculo' => 0,
                        'estado' => 1,
                        'permitir_entregar_sin_ctrl' => 0,
                        'observacion' => '',
                        'fk_clientes' => null,
                        'fk_productos' => null,
                        'fk_ganador' => null,
                    ]],
                    'count' => 1,
                ])
            ),
        ]
    )]
    public function getSorteos(Request $request)
    {
        return response()->json($this->sorteosService->listarSorteos($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/sorteos/insertarBoletosDirecto',
        summary: 'Inserta boletos de participación de sorteo directo (sin pasar por la venta POS)',
        tags: ['Sorteos'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'boletos',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'fk_sorteo', type: 'integer', example: 1, description: 'Sorteo al que pertenece el boleto (obligatorio)'),
                            new OA\Property(property: 'codigo', type: 'string', example: 'ABC123', description: 'Código único del boleto (obligatorio)'),
                            new OA\Property(property: 'fk_cliente', type: 'integer', description: 'Cliente asociado'),
                            new OA\Property(property: 'fk_factura', type: 'integer', description: 'Factura asociada'),
                            new OA\Property(property: 'ref_factura', type: 'string', example: 'FAC-0001'),
                            new OA\Property(property: 'fk_vendedor', type: 'integer', description: 'Vendedor asociado'),
                            new OA\Property(property: 'valor_compra', type: 'integer', example: 50000),
                            new OA\Property(property: 'numero_boleto', type: 'integer', example: 1),
                            new OA\Property(property: 'codigo_seguridad', type: 'string', example: '1234'),
                            new OA\Property(property: 'estado', type: 'integer', enum: [0, 1, 2, 3], example: 0),
                            new OA\Property(property: 'fecha_registro', type: 'string', format: 'date', description: 'Por defecto la fecha actual'),
                        ])
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Boletos insertados correctamente, o error de validación de campos obligatorios (status=1)',
                content: new OA\JsonContent(example: [
                    'status'  => 0,
                    'message' => 'Se insertaron 1 boleto(s) correctamente',
                    'boletos' => [
                        ['rowid' => 10, 'fk_sorteo' => 1, 'numero_boleto' => 1, 'codigo' => 'ABC123', 'estado' => 0],
                    ],
                ])
            ),
            new OA\Response(response: 409, description: 'Códigos duplicados dentro del payload o ya existentes en la base de datos'),
        ]
    )]
    public function insertarBoletosDirectoPos(Request $request)
    {
        return $this->sorteosService->insertarBoletosDirectoPos($request);
    }
}
