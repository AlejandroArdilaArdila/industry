<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Panel\AlertasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Alertas de actividades vencidas (modal del dashboard). */
class AlertasController extends Controller
{
    public function __construct(private AlertasService $alertas)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->alertas->vencidas(Auth::guard('web')->user()));
    }

    /** Silencia algunas alertas (`claves`) o todas las visibles (`todas`) durante unas horas. */
    public function silenciar(Request $request): JsonResponse
    {
        $d = $request->validate(['claves' => ['nullable', 'array', 'max:500'], 'claves.*' => ['string', 'max:40'], 'todas' => ['nullable', 'boolean']]);
        $n = $this->alertas->silenciar(Auth::guard('web')->user(), ! empty($d['todas']) ? null : ($d['claves'] ?? []));

        return response()->json(['ok' => true, 'silenciadas' => $n, 'horas' => AlertasService::HORAS_SILENCIO]);
    }
}
