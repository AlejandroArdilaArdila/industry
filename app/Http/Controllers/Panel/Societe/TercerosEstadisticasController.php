<?php

namespace App\Http\Controllers\Panel\Societe;

use App\Http\Controllers\Controller;
use App\Services\Societe\TercerosEstadisticasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class TercerosEstadisticasController extends Controller
{
    public function __invoke(Request $request, TercerosEstadisticasService $servicio): JsonResponse
    {
        $usuario = Auth::guard('web')->user();
        if ($request->boolean('fresco')) {
            Cache::forget('terceros-estadisticas:'.($usuario->isAdmin() ? 0 : (int) $usuario->getKey()));
        }

        return response()->json($servicio->resumen($usuario));
    }
}
