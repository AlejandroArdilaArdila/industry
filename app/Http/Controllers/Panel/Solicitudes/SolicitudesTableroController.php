<?php

namespace App\Http\Controllers\Panel\Solicitudes;

use App\Http\Controllers\Controller;
use App\Services\Panel\SolicitudesTableroService;
use App\Services\Panel\SolicitudFichaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** Datos del tablero kanban de solicitudes y movimiento de tarjetas entre etapas. */
class SolicitudesTableroController extends Controller
{
    public function __construct(private SolicitudesTableroService $tablero, private SolicitudFichaService $ficha)
    {
    }

    /** Una columna del tablero (con filtros y paginación «ver más»). */
    public function columna(Request $request, string $columna): JsonResponse
    {
        abort_unless(isset(SolicitudesTableroService::COLUMNAS[$columna]), 404);
        $request->merge(['estados' => array_filter(explode(',', (string) $request->query('estados', '')), 'strlen')]);
        $filtros = $request->validate([
            'q' => 'nullable|string|max:100',
            'estados' => 'nullable|array',
            'estados.*' => 'integer',
            'skip' => 'nullable|integer|min:0',
        ]);

        return response()->json($this->tablero->columna(Auth::guard('web')->user(), $columna, $filtros, (int) ($filtros['skip'] ?? 0)));
    }

    public function mover(Request $request, int $solicitud): JsonResponse
    {
        $usuario = Auth::guard('web')->user();
        abort_unless($this->ficha->puedeEditar($usuario), 403, 'No tienes permiso para modificar solicitudes.');
        $sol = $this->ficha->solicitud($solicitud);
        abort_unless($sol && $this->ficha->puedeVer($usuario, $sol), 404, 'Solicitud no encontrada.');
        $d = $request->validate(['estado' => 'required|integer', 'motivo' => 'nullable|integer'], ['estado.required' => 'Indica la etapa de destino.']);

        try {
            $this->tablero->mover($usuario, $sol, (int) $d['estado'], isset($d['motivo']) ? (int) $d['motivo'] : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'message' => 'Solicitud '.$sol->ref.' → '.(SolicitudesGridController::ESTADOS[(string) $d['estado']] ?? '')]);
    }

    /** Motivos de cierre para el diálogo que aparece al soltar una tarjeta en «Cerradas». */
    public function motivos(): JsonResponse
    {
        return response()->json(['items' => $this->ficha->catalogos(Auth::guard('web')->user())['motivos']]);
    }
}
