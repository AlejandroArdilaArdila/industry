<?php

namespace App\Http\Controllers\OrdenVenta;

use App\Http\Controllers\Controller;
use App\Services\OrdenVenta\OrdenVentaService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class OrdenVentaController extends Controller
{
    public function __construct(
        private OrdenVentaService $ordenVentaService,
        private UsuariosService $usuariosService,
    ) {}

    #[OA\Post(
        path: '/api/api.php/integrospos/ordenventa/crear',
        summary: 'Crea una Orden Comercial de Dolibarr (commande/card.php) EN BORRADOR, sin validar. Mismo shape de "lines" que ticket/payment, pero sin resolver precios/impuestos en el servidor',
        security: [['DolApiKey' => []]],
        tags: ['OrdenVenta'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['customerId', 'employeeId', 'lines'],
                properties: [
                    new OA\Property(property: 'customerId', type: 'integer', example: 27),
                    new OA\Property(property: 'employeeId', type: 'integer', example: 20),
                    new OA\Property(property: 'notaPrivada', type: 'string', example: ''),
                    new OA\Property(property: 'refLocal', type: 'string', example: 'APK-20-20260721064007-9'),
                    new OA\Property(property: 'fecha', type: 'string', description: 'Fecha de la orden (Y-m-d). Default: hoy', example: '2026-07-21'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'idProduct', type: 'integer', example: 5869),
                            new OA\Property(property: 'cant', type: 'number', example: 1.0),
                            new OA\Property(property: 'precio', type: 'number', description: 'Fallback si no viene nivelPrecioSinIva', example: 10800.0),
                            new OA\Property(property: 'nivelPrecioSinIva', type: 'number', example: 9075.63025210084),
                            new OA\Property(property: 'nivelPrecioConIva', type: 'number', example: 10800.0),
                            new OA\Property(property: 'tva_tx', type: 'number', example: 19.0),
                            new OA\Property(property: 'discount', type: 'number', description: 'remise_percent de la linea', example: 0.0),
                            new OA\Property(property: 'description', type: 'string', example: '* ACCESORIO CEJAS'),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Orden creada en borrador',
                content: new OA\JsonContent(example: [
                    'success' => true,
                    'idOrden' => 45,
                    'ref' => '(PROV45)',
                    'fk_statut' => 0,
                    'total_ht' => 11932.0,
                    'total_tva' => 2267.0,
                    'total_ttc' => 14199.0,
                ])
            ),
            new OA\Response(response: 401, description: "Falta 'customerId'/'employeeId'/'lines', o cliente/producto inexistente"),
        ]
    )]
    public function crear(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->ordenVentaService->crear($request, $usuario));
    }
}
