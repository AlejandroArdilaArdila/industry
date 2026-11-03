<?php

namespace App\Http\Controllers\Imagenes;

use App\Http\Controllers\Controller;
use App\Services\Imagenes\ImagenesService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ImagenesController extends Controller
{
    public function __construct(private ImagenesService $imagenesService)
    {
    }

    #[OA\Get(
        path: '/api/api.php/integrospos/imagepath/getImagePath',
        summary: 'Sirve directamente la imagen configurada (logo, fondo de login o encabezado de tirilla)',
        tags: ['Imagenes'],
        parameters: [
            new OA\Parameter(
                name: 'tipo',
                in: 'query',
                required: true,
                description: '1 = Logo de la empresa, 2 = Imagen de fondo del login, 3 = Imagen para encabezado de tirilla impresa',
                schema: new OA\Schema(type: 'integer', enum: [1, 2, 3])
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contenido binario de la imagen (Content-Type según el archivo)'),
            new OA\Response(response: 400, description: 'Parámetro "tipo" no especificado o inválido'),
            new OA\Response(response: 404, description: 'Archivo no configurado para ese tipo, o no encontrado en disco'),
        ]
    )]
    public function getImagePath(Request $request)
    {
        $tipo = $request->query('tipo');
        $tipo = $tipo !== null ? (int) $tipo : null;

        $ruta = $this->imagenesService->resolverRutaImagen($tipo);

        return response()->file($ruta, [
            'Content-Disposition' => 'inline; filename="'.basename($ruta).'"',
        ]);
    }
}
