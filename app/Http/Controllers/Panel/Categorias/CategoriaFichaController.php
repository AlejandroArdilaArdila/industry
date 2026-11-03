<?php

namespace App\Http\Controllers\Panel\Categorias;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Services\Categorias\CategoriasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Modal de ficha de una categoría de cualquier tipo: un solo formulario para
 * crear y editar. show()/nuevo() devuelven el HTML del modal.
 */
class CategoriaFichaController extends Controller
{
    public function __construct(private CategoriasService $categorias)
    {
    }

    public function show(Categoria $categoria): JsonResponse
    {
        abort_unless(isset(CategoriasService::TIPOS[(int) $categoria->type]), 404);

        return $this->ficha($categoria);
    }

    public function nuevo(Request $request): JsonResponse
    {
        $this->autorizar();
        $tipo = $request->filled('tipo') ? (int) $request->input('tipo') : null;

        return $this->ficha($this->categorias->nueva($tipo));
    }

    public function store(Request $request): JsonResponse
    {
        $this->autorizar();
        $categoria = $this->categorias->nueva(null);
        $datos = $this->validar($request, $categoria);

        $this->categorias->guardar($categoria, $datos, Auth::guard('web')->user());

        return response()->json($this->respuesta($categoria, 'Categoría creada') + [
            'updateUrl' => route('panel.categorias.update', $categoria->rowid),
        ]);
    }

    public function update(Request $request, Categoria $categoria): JsonResponse
    {
        $this->autorizar();
        $datos = $this->validar($request, $categoria);

        $this->categorias->guardar($categoria, $datos, Auth::guard('web')->user());

        return response()->json($this->respuesta($categoria->refresh(), 'Categoría guardada'));
    }

    public function destroy(Categoria $categoria): JsonResponse
    {
        abort_unless($this->categorias->puedeEliminar(Auth::guard('web')->user()), 403, 'No tienes permiso para eliminar categorías.');

        $this->categorias->eliminar($categoria);

        return response()->json(['ok' => true, 'message' => 'Categoría eliminada']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, Categoria $categoria): array
    {
        $datos = $request->validate(
            $this->categorias->reglas($categoria, $request->all()),
            $this->categorias->mensajes(),
            $this->categorias->atributos()
        );
        $this->categorias->validarRelaciones($categoria, $datos);

        return $datos;
    }

    private function ficha(Categoria $categoria): JsonResponse
    {
        $usuario = Auth::guard('web')->user();
        $html = view('panel.categorias.partials._ficha', $this->categorias->datosFicha($categoria) + [
            'puedeEditar' => $this->categorias->puedeEditar($usuario),
            'puedeEliminar' => $this->categorias->puedeEliminar($usuario),
            'esNuevo' => !$categoria->exists,
        ])->render();

        return response()->json(['html' => $html]);
    }

    private function autorizar(): void
    {
        abort_unless(
            $this->categorias->puedeEditar(Auth::guard('web')->user()),
            403,
            'No tienes permiso para crear o modificar categorías.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function respuesta(Categoria $categoria, string $mensaje): array
    {
        return [
            'ok' => true,
            'message' => $mensaje,
            'categoria' => [
                'rowid' => (int) $categoria->rowid,
                'label' => $categoria->label,
                'tipo' => (int) $categoria->type,
                'color' => $categoria->color ? '#'.$categoria->color : null,
            ],
        ];
    }
}
