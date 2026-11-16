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
        abort_unless(isset($this->tablero->columnas()[$columna]), 404);
        $request->merge(['estados' => array_filter(explode(',', (string) $request->query('estados', '')), 'strlen')]);
        $filtros = $request->validate([
            'q' => 'nullable|string|max:100',
            'estados' => 'nullable|array',
            'estados.*' => 'integer',
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => 'nullable|date_format:Y-m-d',
            'skip' => 'nullable|integer|min:0',
        ]);

        return response()->json($this->tablero->columna(Auth::guard('web')->user(), $columna, $filtros, (int) ($filtros['skip'] ?? 0)));
    }

    /** Guarda los títulos, reglas y orden de las columnas (solo administradores). */
    public function guardarColumnas(Request $request): JsonResponse
    {
        abort_unless(Auth::guard('web')->user()->isAdmin(), 403, 'Solo un administrador puede configurar las columnas.');
        $d = $request->validate([
            'columnas' => 'required|array|min:1',
            'columnas.*.clave' => 'required|string|in:'.implode(',', array_keys(SolicitudesTableroService::COLUMNAS)),
            'columnas.*.titulo' => 'nullable|string|max:40',
            'columnas.*.estados' => 'nullable|array',
            'columnas.*.estados.*' => 'integer',
            'columnas.*.moraMin' => 'nullable|integer|min:0|max:100000',
            'columnas.*.moraMax' => 'nullable|integer|min:0|max:100000',
            'columnas.*.contacto' => 'nullable|in:'.implode(',', SolicitudesTableroService::CONTACTOS),
        ]);
        foreach ($d['columnas'] as $c) {
            if (isset($c['moraMin'], $c['moraMax']) && $c['moraMin'] > $c['moraMax']) {
                return response()->json(['message' => 'En «'.($c['titulo'] ?? $c['clave']).'» la mora mínima no puede ser mayor que la máxima.'], 422);
            }
        }
        $this->tablero->guardarColumnas($d['columnas']);

        return response()->json(['ok' => true]);
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

    /** Fija la urgencia (Alta / Media / Baja) desde la tarjeta. */
    public function urgencia(Request $request, int $solicitud): JsonResponse
    {
        $usuario = Auth::guard('web')->user();
        abort_unless($this->ficha->puedeEditar($usuario), 403, 'No tienes permiso para modificar solicitudes.');
        $sol = $this->ficha->solicitud($solicitud);
        abort_unless($sol && $this->ficha->puedeVer($usuario, $sol), 404, 'Solicitud no encontrada.');
        $d = $request->validate(['valor' => 'required|string|max:30']);

        try {
            $u = $this->tablero->fijarUrgencia($usuario, $sol, $d['valor']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'urgencia' => $u, 'message' => 'Urgencia '.$d['valor'].' en '.$sol->ref]);
    }

    /** Motivos de cierre para el diálogo que aparece al soltar una tarjeta en «Cerradas». */
    public function motivos(): JsonResponse
    {
        return response()->json(['items' => $this->ficha->catalogos(Auth::guard('web')->user())['motivos']]);
    }
}
