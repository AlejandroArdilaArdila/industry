<?php

namespace App\Http\Controllers\Panel\Societe;

use App\Http\Controllers\Controller;
use App\Models\Sociedad;
use App\Services\Societe\TerceroFichaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Modal de ficha de un tercero (asistente por pasos). show()/nuevo() devuelven
 * el HTML del modal ya renderizado en Blade; store() crea el tercero con el
 * primer paso y update() guarda un solo paso.
 */
class TerceroFichaController extends Controller
{
    public function __construct(private TerceroFichaService $fichaService)
    {
    }

    public function show(Sociedad $tercero): JsonResponse
    {
        return $this->ficha($tercero);
    }

    public function nuevo(): JsonResponse
    {
        abort_unless($this->fichaService->puedeEditar(Auth::guard('web')->user()), 403, 'No tienes permiso para crear terceros.');

        return $this->ficha($this->fichaService->nuevo());
    }

    public function store(Request $request): JsonResponse
    {
        $usuario = Auth::guard('web')->user();
        abort_unless($this->fichaService->puedeEditar($usuario), 403, 'No tienes permiso para crear terceros.');

        $datos = $request->validate(
            $this->fichaService->reglas($this->fichaService->nuevo(), 'identificacion'),
            $this->fichaService->mensajes(),
            $this->fichaService->atributos()
        );

        $tercero = $this->fichaService->crear($datos, $request->input('ef', []), $usuario);

        return response()->json($this->respuesta($tercero, 'Tercero creado') + [
            'updateUrl' => route('panel.societe.terceros.update', $tercero->rowid),
        ]);
    }

    public function update(Request $request, Sociedad $tercero): JsonResponse
    {
        $usuario = Auth::guard('web')->user();
        abort_unless($this->fichaService->puedeEditar($usuario), 403, 'No tienes permiso para modificar terceros.');

        $request->validate(['seccion' => ['required', Rule::in(array_keys(TerceroFichaService::SECCIONES))]]);
        $seccion = $request->input('seccion');

        $datos = $request->validate(
            $this->fichaService->reglas($tercero, $seccion),
            $this->fichaService->mensajes(),
            $this->fichaService->atributos()
        );

        $this->fichaService->guardar($tercero, $seccion, $datos, $request->input('ef', []), $usuario);

        return response()->json($this->respuesta($tercero, null));
    }

    private function ficha(Sociedad $tercero): JsonResponse
    {
        $html = view('panel.societe.partials._ficha', $this->fichaService->datosFicha($tercero) + [
            'puedeEditar' => $this->fichaService->puedeEditar(Auth::guard('web')->user()),
            'esNuevo' => !$tercero->exists,
        ])->render();

        return response()->json(['html' => $html]);
    }

    /**
     * @return array<string, mixed>
     */
    private function respuesta(Sociedad $tercero, ?string $mensaje): array
    {
        return [
            'ok' => true,
            'message' => $mensaje,
            'tercero' => [
                'rowid' => (int) $tercero->rowid,
                'nom' => $tercero->nom,
                'siren' => $tercero->siren,
                'status' => (string) $tercero->status,
                'code_client' => $tercero->code_client,
                'code_fournisseur' => $tercero->code_fournisseur,
            ],
        ];
    }
}
