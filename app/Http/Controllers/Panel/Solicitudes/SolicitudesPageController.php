<?php

namespace App\Http\Controllers\Panel\Solicitudes;

use App\Http\Controllers\Controller;
use App\Services\Panel\SolicitudesEstadisticasService;
use App\Services\Panel\SolicitudesTableroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SolicitudesPageController extends Controller
{
    public function index(SolicitudesTableroService $tablero): View
    {
        return view('panel.solicitudes.solicitudes', [
            'estados' => SolicitudesGridController::ESTADOS,
            'tablero' => $tablero->definicion(),
        ]);
    }

    /** Tarjetas de arriba del listado; `?fresco=1` salta la caché (tras crear o editar una solicitud). */
    public function estadisticas(Request $request, SolicitudesEstadisticasService $servicio): JsonResponse
    {
        $usuario = Auth::guard('web')->user();
        if ($request->boolean('fresco')) {
            Cache::forget('solicitudes-estadisticas:'.($usuario->isAdmin() ? 0 : (int) $usuario->getKey()));
        }

        return response()->json($servicio->resumen($usuario));
    }
}
