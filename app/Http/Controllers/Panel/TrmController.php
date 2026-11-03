<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Panel\TrmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TrmController extends Controller
{
    public function __construct(private TrmService $trm)
    {
    }

    private function esAdmin(): bool
    {
        return (bool) Auth::guard('web')->user()?->isAdmin();
    }

    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function historial(Request $request): JsonResponse
    {
        $moneda = strtoupper((string) $request->query('moneda', 'USD'));
        abort_unless(in_array($moneda, $this->trm->monedasConfiguradas(), true) || $moneda === 'USD', 422, 'Moneda no disponible.');

        $hasta = $request->query('hasta') ?: Carbon::today()->toDateString();
        $desde = $request->query('desde') ?: Carbon::parse($hasta)->subDays(89)->toDateString();
        abort_unless(strtotime((string) $desde) && strtotime((string) $hasta), 422, 'Fechas inválidas.');

        return response()->json($this->trm->historial($moneda, $desde, $hasta));
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($this->esAdmin(), 403, 'Solo un administrador puede configurar las monedas.');

        $datos = $request->validate([
            'monedas' => ['required', 'array', 'min:2', 'max:'.TrmService::MAX_MONEDAS],
            'monedas.*' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'distinct'],
            'colores' => ['nullable', 'array'],
            'colores.general' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'colores.borde' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'colores.fondo' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'colores.fuente' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'colores.monedas' => ['nullable', 'array'],
            'colores.monedas.*' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'colores.*' => 'Color inválido: usa el formato #RRGGBB.',
            'monedas.min' => 'Deja al menos dos monedas: las dos primeras se muestran siempre.',
            'monedas.max' => 'Máximo '.TrmService::MAX_MONEDAS.' monedas.',
        ]);

        $catalogo = $this->trm->catalogo();
        foreach ($datos['monedas'] as $codigo) {
            abort_unless(isset($catalogo[$codigo]), 422, "La moneda {$codigo} no está disponible en la fuente.");
        }

        $this->trm->guardarMonedas($datos['monedas']);
        $porMoneda = array_filter(
            array_intersect_key($datos['colores']['monedas'] ?? [], array_flip($datos['monedas'])),
            fn ($v) => ! empty($v),
        );
        $tema = array_map(
            fn ($k) => ! empty($datos['colores'][$k]) ? strtolower($datos['colores'][$k]) : null,
            array_combine(['general', 'borde', 'fondo', 'fuente'], ['general', 'borde', 'fondo', 'fuente']),
        );
        $this->trm->guardarColores($tema, array_map('strtolower', $porMoneda));
        try {
            $this->trm->cargar($datos['monedas'], Carbon::today()->subDays(400)->toDateString());
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($this->payload() + ['message' => 'Monedas guardadas']);
    }

    public function cargar(): JsonResponse
    {
        abort_unless($this->esAdmin(), 403, 'Solo un administrador puede cargar la TRM.');

        try {
            $n = $this->trm->cargar($this->trm->monedasConfiguradas());
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'No se pudo consultar la fuente de la TRM: '.$e->getMessage());
        }

        return response()->json($this->payload() + ['message' => "TRM cargada ({$n} registros)"]);
    }

    private function payload(): array
    {
        $datos = $this->trm->tasas();
        $datos['colores'] = $this->trm->coloresConfigurados();
        $datos['puedeConfigurar'] = $this->esAdmin();
        if ($datos['puedeConfigurar']) {
            $datos['config'] = ['monedas' => $this->trm->monedasConfiguradas(), 'maximo' => TrmService::MAX_MONEDAS];
            try {
                $datos['config']['catalogo'] = collect($this->trm->catalogo())->map(fn ($f, $c) => ['codigo' => $c, 'fuente' => $f])->values()->all();
            } catch (\Throwable) {
                $datos['config']['catalogo'] = [];
            }
        }

        return $datos;
    }
}
