<?php

namespace App\Http\Controllers\Panel\Productos;

use App\Http\Controllers\Controller;
use App\Services\Productos\ProductosEstadisticasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ProductosEstadisticasController extends Controller
{
    public function __invoke(Request $request, ProductosEstadisticasService $servicio): JsonResponse
    {
        $usuario = Auth::guard('web')->user();
        if ($request->boolean('fresco')) {
            Cache::forget('productos-estadisticas:'.($usuario->isAdmin() ? 0 : (int) $usuario->getKey()));
        }

        return response()->json($servicio->resumen($usuario));
    }
}
