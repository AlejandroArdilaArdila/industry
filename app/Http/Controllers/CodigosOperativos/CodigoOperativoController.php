<?php

namespace App\Http\Controllers\CodigosOperativos;

use App\Http\Controllers\Controller;
use App\Services\CodigosOperativos\CodigoOperativoService;
use OpenApi\Attributes as OA;

class CodigoOperativoController extends Controller
{
    public function __construct(
        private CodigoOperativoService $codigoOperativoService,
    ) {
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/products/codigosOperativos',
        summary: 'Codigos operativos activos y el producto/servicio asociado a cada uno',
        security: [['DolApiKey' => []]],
        tags: ['Productos'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Codigos operativos ordenados por rowid descendente',
                content: new OA\JsonContent(example: [[
                    'rowid' => 3,
                    'codigo' => '010',
                    'fk_product' => 5,
                    'descuento' => 1,
                    'ref' => 'PROD001',
                    'label' => 'Producto de ejemplo',
                    'tipo' => 'Producto',
                    'date_creation' => '2026-08-01 10:00:00',
                ]])
            ),
        ]
    )]
    public function getCodigosOperativos()
    {
        return response()->json($this->codigoOperativoService->listar());
    }
}
