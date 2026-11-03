<?php

namespace App\Http\Controllers\TicketTemplate;

use App\Http\Controllers\Controller;
use App\Services\TicketTemplate\TicketTemplateService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TicketTemplateController extends Controller
{
    public function __construct(private TicketTemplateService $ticketTemplateService)
    {
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/tickettemplate/{id}',
        summary: 'Obtiene una plantilla de tirilla por rowid o por nombre (o todas si id=0/null)',
        tags: ['TicketTemplate'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: "Rowid numerico, nombre de la plantilla, o '0'/'null' para traerlas todas",
                schema: new OA\Schema(type: 'string'),
                example: '0'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Una plantilla (objeto) o todas las plantillas (array), segun el id enviado',
                content: new OA\JsonContent(example: [
                    'rowid' => 1,
                    'nombre' => 'venta',
                    'label' => 'Venta / Factura',
                    'config' => [],
                    'activo' => 1,
                ])
            ),
            new OA\Response(response: 401, description: 'ID/nombre invalido o plantilla no encontrada'),
        ]
    )]
    public function plantillaTicket(string $id)
    {
        return response()->json($this->ticketTemplateService->plantillaTicketPorIdONombre($id));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/tickettemplate/tipos',
        summary: 'Lista todos los tipos de ticket disponibles',
        tags: ['TicketTemplate'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de tipos de ticket',
                content: new OA\JsonContent(example: [
                    'tipos' => [
                        ['rowid' => 1, 'nombre' => 'venta', 'label' => 'Venta / Factura', 'activo' => 1],
                        ['rowid' => 2, 'nombre' => 'nota_credito', 'label' => 'Nota Crédito', 'activo' => 1],
                    ],
                ])
            ),
        ]
    )]
    public function tiposTicket()
    {
        return response()->json($this->ticketTemplateService->tiposTicket());
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/tickettemplate/get',
        summary: 'Obtiene la configuracion completa de un tipo de ticket por nombre',
        tags: ['TicketTemplate'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['nombre'],
                properties: [
                    new OA\Property(property: 'nombre', type: 'string', example: 'venta'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Configuracion del tipo de ticket',
                content: new OA\JsonContent(example: [
                    'rowid' => 1,
                    'nombre' => 'venta',
                    'label' => 'Venta / Factura',
                    'config' => [],
                    'dato_preview' => [],
                    'columnas' => 80,
                    'variables' => [],
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'nombre' o el tipo no fue encontrado"),
        ]
    )]
    public function configuracionTicket(Request $request)
    {
        return response()->json($this->ticketTemplateService->configuracionTicket($request));
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/tickettemplate/variables',
        summary: 'Lista todas las variables globales de campo para tirillas',
        tags: ['TicketTemplate'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de variables',
                content: new OA\JsonContent(example: [
                    'variables' => [
                        ['rowid' => 1, 'nombre' => 'empresa', 'descripcion' => 'Nombre de la empresa', 'tipo' => 'string'],
                    ],
                ])
            ),
        ]
    )]
    public function variablesTicket()
    {
        return response()->json($this->ticketTemplateService->variablesTicket());
    }

    #[OA\Post(
        path: '/api/api.php/integrospos/tickettemplate/secciones',
        summary: 'Lista todas las secciones reutilizables de tirillas',
        tags: ['TicketTemplate'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de secciones',
                content: new OA\JsonContent(example: [
                    'secciones' => [[
                        'rowid' => 1,
                        'nombre' => 'encabezado',
                        'descripcion' => 'Encabezado con logo y datos de la empresa',
                        'elementos' => [],
                        'condicional' => null,
                        'repeat' => null,
                    ]],
                ])
            ),
        ]
    )]
    public function seccionesTicket()
    {
        return response()->json($this->ticketTemplateService->seccionesTicket());
    }
}
