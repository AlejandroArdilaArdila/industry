<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Ia\IaException;
use App\Services\Ia\TareaIaService;
use App\Services\Microsoft\MicrosoftSyncService;
use App\Services\Panel\PanelDashboardService;
use App\Services\Panel\TareasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** Tareas del programador: formulario de creación (libre o relacionada con cualquier elemento), detalle, cierre y aplazamiento. */
class TareasController extends Controller
{
    public function __construct(private TareasService $tareas, private MicrosoftSyncService $microsoft, private PanelDashboardService $dashboard)
    {
    }

    private function usuario()
    {
        return Auth::guard('web')->user();
    }

    /** HTML del formulario; con ?tipo=&ref= llega ya relacionada con un elemento (p. ej. desde un documento abierto). */
    public function formulario(Request $request): JsonResponse
    {
        $u = $this->usuario();
        $previo = null;
        if (isset(TareasService::TIPOS[$request->query('tipo')]) && $request->integer('ref') > 0) {
            $previo = $this->tareas->elementos([[$request->query('tipo'), $request->integer('ref')]])[$request->query('tipo').':'.$request->integer('ref')] ?? null;
        }

        $fecha = $request->query('fecha');
        $fecha = is_string($fecha) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && $fecha >= Carbon::now(TareasService::TZ)->toDateString() ? $fecha : Carbon::now(TareasService::TZ)->addDay()->toDateString();

        return response()->json(['html' => view('panel.actividades.partials._tarea_form', [
            'cat' => $this->tareas->catalogos(),
            'tipos' => TareasService::TIPOS,
            'ms' => $this->microsoft->estado((int) $u->getKey()),
            'previo' => $previo,
            'yo' => (int) $u->getKey(),
            'hoy' => Carbon::now(TareasService::TZ)->toDateString(),
            // Día elegido en el programador (?fecha=): la tarea nace en esa fecha; si es hoy, a la próxima hora en punto.
            'fechaInicial' => $fecha,
            'horaInicial' => $this->horaInicial($fecha),
            'colores' => $this->dashboard->coloresEtapas(),
        ])->render()]);
    }

    /** Hora por defecto: 08:00; si la fecha es hoy, la próxima hora en punto (sin pasarse de las 23:00). */
    private function horaInicial(string $fecha): string
    {
        $ahora = Carbon::now(TareasService::TZ);
        if ($fecha !== $ahora->toDateString()) {
            return '08:00';
        }
        $sig = $ahora->copy()->addHour();

        return $sig->toDateString() === $fecha ? $sig->format('H:00') : '23:00';
    }

    public function elementos(Request $request): JsonResponse
    {
        abort_unless(isset(TareasService::TIPOS[$request->query('tipo')]), 422, 'Tipo inválido.');

        return response()->json(['items' => $this->tareas->buscar($this->usuario(), $request->query('tipo'), (string) $request->query('q', ''))]);
    }

    public function store(Request $request): JsonResponse
    {
        $hoy = Carbon::now(TareasService::TZ)->toDateString();
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:250'],
            'fecha' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$hoy],
            'hora' => ['nullable', 'date_format:H:i'],
            'prioridad' => ['nullable', 'integer'],
            'recurrencia' => ['nullable', 'integer', 'in:1,2,3,4'],
            'tipo' => ['nullable', 'in:'.implode(',', array_keys(TareasService::TIPOS))],
            'ref' => ['nullable', 'integer', 'min:1'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'integrantes' => ['nullable', 'array'],
            'integrantes.*' => ['integer'],
            'categoria' => ['nullable', 'integer'],
            'state_change' => ['nullable', 'in:0,1'],
            'teams' => ['nullable', 'boolean'],
        ], ['titulo.required' => 'Escribe o dicta el asunto de la tarea.', 'fecha.after_or_equal' => 'La fecha no puede ser anterior a hoy.']);

        $r = $this->tareas->crear($this->usuario(), $d);
        $ms = $this->microsoft->empujarTarea($r['id'], (int) $this->usuario()->getKey(), array_key_exists('teams', $d) ? (bool) $d['teams'] : null);
        $extra = ! empty($ms['calendario']) || ! empty($ms['tarea']) ? ' · sincronizada con Office 365' : '';

        return response()->json(['ok' => true, 'message' => 'Tarea creada'.$extra, 'id' => $r['id'], 'programada' => $r['programada'], 'microsoft' => $ms]);
    }

    public function show(int $tarea): JsonResponse
    {
        $d = $this->tareas->detalle($this->usuario(), $tarea);
        abort_unless($d, 404, 'Tarea no encontrada.');

        return response()->json(['html' => view('panel.actividades.partials._tarea', ['t' => $d, 'colores' => $this->dashboard->coloresEtapas(), 'dolibarr' => config('services.dolibarr.url')])->render()]);
    }

    public function estado(Request $request, int $tarea): JsonResponse
    {
        $d = $request->validate(['cerrada' => ['required', 'boolean']]);
        try {
            $this->tareas->cerrar($this->usuario(), $tarea, (bool) $d['cerrada']);
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }
        $this->microsoft->empujarEstadoTarea($tarea, (bool) $d['cerrada']);

        return response()->json(['ok' => true, 'message' => $d['cerrada'] ? 'Tarea completada' : 'Tarea reabierta']);
    }

    public function destroy(int $tarea): JsonResponse
    {
        // Primero Office 365 (necesita los vínculos), después la base de datos.
        $d = $this->tareas->detalle($this->usuario(), $tarea);
        abort_unless($d, 404, 'Tarea no encontrada.');
        abort_unless($d['puedeEliminar'], 403, 'Solo quien creó la tarea puede eliminarla.');
        $this->microsoft->eliminarTarea($tarea);
        try {
            $this->tareas->eliminar($this->usuario(), $tarea);
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['ok' => true, 'message' => 'Tarea eliminada']);
    }

    public function posponer(Request $request, int $tarea): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Carbon::now(TareasService::TZ)->toDateString()], 'hora' => ['nullable', 'date_format:H:i']]);
        try {
            $this->tareas->posponer($this->usuario(), $tarea, $d['fecha'], $d['hora'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }
        $this->microsoft->reprogramarTarea($tarea);

        return response()->json(['ok' => true, 'message' => 'Tarea reprogramada']);
    }

    /** Dictado / texto libre → campos del formulario (la IA propone, el usuario confirma). */
    public function interpretar(Request $request, TareaIaService $ia): JsonResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'max:2000']], ['texto.required' => 'Dicta o escribe qué necesitas programar.']);
        try {
            return response()->json(['ok' => true] + $ia->interpretar($this->usuario(), $d['texto']));
        } catch (IaException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
