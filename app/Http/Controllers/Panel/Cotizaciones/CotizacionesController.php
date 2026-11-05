<?php

namespace App\Http\Controllers\Panel\Cotizaciones;

use App\Http\Controllers\Controller;
use App\Services\Panel\CotizacionesTableroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Tablero kanban de cotizaciones activas. */
class CotizacionesController extends Controller
{
    public function __construct(private CotizacionesTableroService $tablero)
    {
    }

    public function index(): View
    {
        return view('panel.cotizaciones.cotizaciones', ['tablero' => $this->tablero->definicion()]);
    }

    public function columna(Request $request, string $columna): JsonResponse
    {
        abort_unless(isset(CotizacionesTableroService::COLUMNAS[$columna]), 404);
        $d = $request->validate(['q' => 'nullable|string|max:100', 'estado' => 'nullable|integer', 'skip' => 'nullable|integer|min:0']);

        return response()->json($this->tablero->columna(Auth::guard('web')->user(), $columna, $d['q'] ?? null, isset($d['estado']) ? (int) $d['estado'] : null, (int) ($d['skip'] ?? 0)));
    }
}
