<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Microsoft\MicrosoftSyncService;
use App\Services\Panel\ActividadesService;
use App\Services\Panel\PanelDashboardService;
use App\Services\Panel\TareasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** Programador de actividades del dashboard: recordatorios y seguimientos de solicitudes, cotizaciones y órdenes. */
class ActividadesController extends Controller
{
    public function __construct(private ActividadesService $actividades, private PanelDashboardService $dashboard, private MicrosoftSyncService $microsoft, private TareasService $tareas)
    {
    }

    private function usuario()
    {
        return Auth::guard('web')->user();
    }

    public function index(Request $request): JsonResponse
    {
        $vista = (string) $request->query('vista', 'pendientes');
        $usuario = $this->usuario();

        if ($vista === 'historial') {
            $r = $this->actividades->recordatorios($usuario, 'pendientes', 1)['resumen'];
            $t = $this->tareas->resumen($usuario);

            return response()->json(['vista' => $vista, 'items' => $this->actividades->misSeguimientos($usuario), 'resumen' => array_map(fn ($k) => (int) $r[$k] + $t[$k], array_combine(array_keys($r), array_keys($r)))]);
        }

        // Recordatorios de seguimientos (actioncomm) + tareas (solubitacora_tareas), en una sola lista.
        $estado = $vista === 'realizados' ? 'realizados' : 'pendientes';
        $r = $this->actividades->recordatorios($usuario, $estado);
        $items = array_merge($r['items'], $this->tareas->items($usuario, $estado));
        usort($items, fn ($a, $b) => $estado === 'pendientes' ? strcmp($a['cuando'], $b['cuando']) : strcmp($b['cuando'], $a['cuando']));
        $t = $this->tareas->resumen($usuario);

        return response()->json(['vista' => $vista, 'items' => $items, 'resumen' => array_map(fn ($k) => (int) $r['resumen'][$k] + $t[$k], array_combine(array_keys($r['resumen']), array_keys($r['resumen'])))]);
    }

    public function documentos(Request $request): JsonResponse
    {
        $modulo = $request->query('tipo');
        abort_unless($modulo === null || $modulo === '' || isset(ActividadesService::MODULOS[$modulo]), 422, 'Tipo inválido.');

        return response()->json(['items' => $this->actividades->buscar($this->usuario(), (string) $request->query('q', ''), $modulo ?: null)]);
    }

    /** Resumen mensual del cliente de un documento al que el usuario tiene acceso. */
    public function clienteMeses(Request $request): JsonResponse
    {
        $modulo = (string) $request->query('modulo', '');
        abort_unless(isset(ActividadesService::MODULOS[$modulo]), 422, 'Tipo inválido.');
        $doc = $this->actividades->documento($this->usuario(), $modulo, $request->integer('id'));
        abort_unless($doc && $doc['fkSoc'] > 0, 403, 'No tienes acceso a este documento.');

        return response()->json(['cliente' => $doc['empresa']] + $this->actividades->mesesCliente((int) $doc['fkSoc']));
    }

    public function panel(Request $request): JsonResponse
    {
        $modulo = (string) $request->query('modulo', '');
        $id = $request->integer('id');
        $doc = null;
        $historial = [];

        if ($modulo !== '' && $id > 0) {
            abort_unless(isset(ActividadesService::MODULOS[$modulo]), 422, 'Tipo inválido.');
            $doc = $this->actividades->documento($this->usuario(), $modulo, $id);
            abort_unless($doc, 403, 'No tienes acceso a este documento.');
            $historial = $this->actividades->historial($modulo, $id);
        }

        $html = view('panel.actividades.partials._panel', [
            'doc' => $doc,
            'cliente' => $doc ? $this->actividades->resumenCliente((int) $doc['fkSoc']) : null,
            'detalle' => $doc ? $this->actividades->detalleDocumento($modulo, $id) : null,
            'relacionados' => $doc ? $this->relacionadosSeguros($modulo, $id) : [],
            'historial' => $historial,
            'fijo' => $request->boolean('fijo'),
            'ms' => $this->microsoft->estado((int) $this->usuario()->getKey()),
            'colores' => $this->dashboard->coloresEtapas(),
        ])->render();

        return response()->json(['html' => $html]);
    }

    public function store(Request $request): JsonResponse
    {
        $hoy = Carbon::now(ActividadesService::TZ)->toDateString();
        $datos = $request->validate([
            'modulo' => ['required', 'in:'.implode(',', array_keys(ActividadesService::MODULOS))],
            'id' => ['required', 'integer', 'min:1'],
            'observacion' => ['required', 'string', 'max:5000'],
            'programar' => ['nullable', 'boolean'],
            'fecha' => ['nullable', 'required_if:programar,1', 'date_format:Y-m-d', 'after_or_equal:'.$hoy],
            'hora' => ['nullable', 'required_if:programar,1', 'date_format:H:i'],
            'titulo' => ['nullable', 'required_if:programar,1', 'string', 'max:255'],
            'teams' => ['nullable', 'boolean'],
        ], [
            'observacion.required' => 'Escribe la observación.',
            'fecha.required_if' => 'Indica la fecha del próximo contacto.',
            'fecha.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
            'hora.required_if' => 'Indica la hora.',
            'titulo.required_if' => 'Escribe el título del recordatorio.',
        ]);

        $programar = ! empty($datos['programar']);

        try {
            $r = $this->actividades->crear($this->usuario(), $datos['modulo'], (int) $datos['id'], trim($datos['observacion']),
                $programar ? $datos['fecha'] : null, $programar ? $datos['hora'] : null, $programar ? trim($datos['titulo']) : null);
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }

        // Office 365 (si el usuario lo conectó): evento de Outlook, tarea de To Do y reunión de Teams. Nunca bloquea el guardado.
        $ms = $r['evento'] ? $this->microsoft->empujarCreado((int) $this->usuario()->getKey(), (int) $r['evento'], array_key_exists('teams', $datos) ? (bool) $datos['teams'] : null) : [];
        $extra = ! empty($ms['calendario']) || ! empty($ms['tarea']) ? ' · sincronizado con Office 365' : '';

        return response()->json(['ok' => true, 'message' => ($r['evento'] ? 'Seguimiento y recordatorio guardados' : 'Seguimiento guardado').$extra, 'microsoft' => $ms] + $r);
    }

    public function estado(Request $request, int $evento): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['required', 'in:REALIZADO,PENDIENTE'],
            'actividad' => ['required_if:estado,REALIZADO', 'nullable', 'string', 'max:2000'],
        ], ['actividad.required_if' => 'Describe la actividad realizada.']);

        try {
            $this->actividades->cambiarEstado($this->usuario(), $evento, $datos['estado'], trim((string) ($datos['actividad'] ?? '')));
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        $this->microsoft->empujarEstado($evento, $datos['estado'] === 'REALIZADO');

        return response()->json(['ok' => true, 'message' => $datos['estado'] === 'REALIZADO' ? 'Actividad marcada como realizada' : 'Actividad reabierta']);
    }

    public function posponer(Request $request, int $evento): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Carbon::now(ActividadesService::TZ)->toDateString()], 'hora' => ['nullable', 'date_format:H:i']]);
        try {
            $this->actividades->posponer($this->usuario(), $evento, $d['fecha'], $d['hora'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }
        $this->microsoft->reprogramarRecordatorio($evento);

        return response()->json(['ok' => true, 'message' => 'Recordatorio reprogramado']);
    }

    /** Los documentos relacionados son un extra: si algo falla no debe tumbar el panel. */
    private function relacionadosSeguros(string $modulo, int $id): array
    {
        try {
            return $this->actividades->relacionados($modulo, $id);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
