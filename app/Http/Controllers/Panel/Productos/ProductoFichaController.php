<?php

namespace App\Http\Controllers\Panel\Productos;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Services\Productos\ProductoFichaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Modal de ficha de un producto o servicio (asistente por pasos).
 * show()/nuevo() devuelven el HTML del modal; store() crea el producto con el
 * primer paso y update() guarda un solo paso.
 */
class ProductoFichaController extends Controller
{
    public function __construct(private ProductoFichaService $fichaService)
    {
    }

    public function show(Producto $producto): JsonResponse
    {
        return $this->ficha($producto);
    }

    public function nuevo(Request $request): JsonResponse
    {
        $tipo = $this->tipo($request);
        $this->autorizar($tipo);

        return $this->ficha($this->fichaService->nuevo($tipo));
    }

    public function store(Request $request): JsonResponse
    {
        $tipo = $this->tipo($request);
        $this->autorizar($tipo);

        $datos = $request->validate(
            $this->fichaService->reglas($this->fichaService->nuevo($tipo), 'general'),
            $this->fichaService->mensajes(),
            $this->fichaService->atributos()
        );

        $producto = $this->fichaService->crear($tipo, $datos, Auth::guard('web')->user());

        return response()->json($this->respuesta($producto, $tipo === ProductoFichaService::TIPO_SERVICIO ? 'Servicio creado' : 'Producto creado') + [
            'updateUrl' => route('panel.productos.update', $producto->rowid),
        ]);
    }

    public function update(Request $request, Producto $producto): JsonResponse
    {
        $this->autorizar((int) $producto->fk_product_type);

        $request->validate(['seccion' => ['required', Rule::in(array_keys(ProductoFichaService::SECCIONES))]]);
        $seccion = $request->input('seccion');

        $datos = $request->validate(
            $this->fichaService->reglas($producto, $seccion),
            $this->fichaService->mensajes(),
            $this->fichaService->atributos()
        );

        $codigos = [];
        if ($seccion === 'codigos') {
            [$codigos, $errores] = $this->fichaService->codigosDesdeTexto($datos['codigos_barras'] ?? '', $producto);
            if ($errores) {
                throw ValidationException::withMessages($errores);
            }
        }

        $this->fichaService->guardar($producto, $seccion, $datos, $request->input('ef', []), $codigos, Auth::guard('web')->user());

        return response()->json($this->respuesta($producto->refresh(), null));
    }

    private function ficha(Producto $producto): JsonResponse
    {
        $html = view('panel.productos.partials._ficha', $this->fichaService->datosFicha($producto) + [
            'puedeEditar' => $this->fichaService->puedeEditar(Auth::guard('web')->user(), (int) $producto->fk_product_type),
            'esNuevo' => !$producto->exists,
        ])->render();

        return response()->json(['html' => $html]);
    }

    private function tipo(Request $request): int
    {
        return (int) $request->input('tipo') === ProductoFichaService::TIPO_SERVICIO
            ? ProductoFichaService::TIPO_SERVICIO
            : ProductoFichaService::TIPO_PRODUCTO;
    }

    private function autorizar(int $tipo): void
    {
        abort_unless(
            $this->fichaService->puedeEditar(Auth::guard('web')->user(), $tipo),
            403,
            $tipo === ProductoFichaService::TIPO_SERVICIO ? 'No tienes permiso para crear o modificar servicios.' : 'No tienes permiso para crear o modificar productos.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function respuesta(Producto $producto, ?string $mensaje): array
    {
        return [
            'ok' => true,
            'message' => $mensaje,
            'producto' => [
                'rowid' => (int) $producto->rowid,
                'ref' => $producto->ref,
                'label' => $producto->label,
                'price_ttc' => (float) $producto->price_ttc,
                'tva_tx' => (float) $producto->tva_tx,
                'tosell' => (string) $producto->tosell,
                'tobuy' => (string) $producto->tobuy,
            ],
        ];
    }
}
