<?php

namespace App\Http\Controllers\Categorias;

use App\Http\Controllers\Controller;
use App\Services\Categorias\CategoriaService;
use App\Services\Usuarios\UsuariosService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CategoriaController extends Controller
{
    public function __construct(
        private CategoriaService $categoriaService,
        private UsuariosService $usuariosService,
    ) {
    }

    #[OA\Get(
        path: '/api/api.php/integrosone/productos/categorias',
        summary: 'Categorias de producto activas del ERP, con el total de productos asociados a cada una',
        security: [['DolApiKey' => []]],
        tags: ['Categorias'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Categorias ordenadas por label',
                content: new OA\JsonContent(example: [[
                    'rowid' => 12,
                    'label' => 'Bebidas',
                    'description' => 'Gaseosas, jugos y aguas',
                    'color' => '3399CC',
                    'fk_parent' => 0,
                    'total_productos' => 45,
                ]])
            ),
            new OA\Response(response: 401, description: "DOLAPIKEY invalida o el usuario no tiene el permiso 'lire' del modulo iapk"),
        ]
    )]
    public function getCategorias(Request $request)
    {
        $usuario = $this->usuariosService->obtenerPorApiKey($request);

        return response()->json($this->categoriaService->listar($usuario));
    }
}
