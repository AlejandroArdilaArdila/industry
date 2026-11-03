<?php

namespace App\Http\Controllers\Carrusel;

use App\Http\Controllers\Controller;
use App\Services\Carrusel\CarruselService;
use OpenApi\Attributes as OA;

class CarruselController extends Controller
{
    public function __construct(private CarruselService $carruselService)
    {
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/carrusel/activas',
        summary: 'Imagenes vigentes hoy del carrusel del Price Checker, en base64',
        security: [['DolApiKey' => []]],
        tags: ['Carrusel'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Imagenes vigentes',
                content: new OA\JsonContent(example: [
                    'total' => 1,
                    'imagenes' => [[
                        'rowid' => 1,
                        'label' => 'Promo fin de mes',
                        'date_start' => '2026-09-10',
                        'date_end' => '2026-09-30',
                        'imagen_base64' => 'data:image/webp;base64,UklGRiI...',
                    ]],
                ])
            ),
            new OA\Response(response: 401, description: "Falta o es invalido el header DOLAPIKEY"),
        ]
    )]
    public function activas()
    {
        return response()->json($this->carruselService->obtenerActivas());
    }
}
