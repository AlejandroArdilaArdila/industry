<?php

namespace App\Http\Controllers\Panel\Solicitudes;

use App\Http\Controllers\Controller;
use App\Services\Ia\IaException;
use App\Services\Ia\SolicitudCorreoIaService;
use App\Services\Panel\ActividadesService;
use App\Services\Panel\PanelDashboardService;
use App\Services\Panel\SolicitudFichaService;
use App\Services\Panel\SolicitudProductosIaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Ficha de una solicitud en panel lateral (crear, editar, cerrar y reabrir).
 * show()/nuevo() devuelven el HTML; store() crea con el primer paso y update() guarda un paso.
 */
class SolicitudFichaController extends Controller
{
    public function __construct(private SolicitudFichaService $servicio, private PanelDashboardService $dashboard)
    {
    }

    private function usuario()
    {
        return Auth::guard('web')->user();
    }

    public function nuevo(): JsonResponse
    {
        abort_unless($this->servicio->puedeEditar($this->usuario()), 403, 'No tienes permiso para crear solicitudes.');

        return $this->ficha(null);
    }

    public function show(int $solicitud): JsonResponse
    {
        return $this->ficha($this->cargar($solicitud));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->servicio->puedeEditar($this->usuario()), 403, 'No tienes permiso para crear solicitudes.');
        $datos = $request->validate($this->reglasGeneral(), [], $this->atributos());
        $id = $this->servicio->crear($this->usuario(), $datos);
        $sol = $this->servicio->solicitud($id);

        return response()->json([
            'ok' => true,
            'message' => 'Solicitud '.$sol->ref.' creada',
            'titulo' => $sol->ref,
            'ref' => $sol->ref,
            'id' => $id,
            'updateUrl' => route('panel.solicitudes.update', $id),
        ]);
    }

    public function update(Request $request, int $solicitud): JsonResponse
    {
        $sol = $this->cargar($solicitud);
        abort_unless($this->servicio->puedeEditar($this->usuario()), 403, 'No tienes permiso para modificar solicitudes.');
        $seccion = $request->validate(['seccion' => 'required|in:general,productos,detalle'])['seccion'];

        $datos = match ($seccion) {
            'general' => $request->validate($this->reglasGeneral(), [], $this->atributos()),
            'productos' => $request->validate([
                'lineas' => 'nullable|array|max:200',
                'lineas.*.id' => 'nullable|integer',
                'lineas.*.fk_product' => 'nullable|integer',
                'lineas.*.description' => 'required|string|max:2000',
                'lineas.*.model' => 'nullable|string|max:40',
                'lineas.*.qty' => 'required|numeric|gt:0',
                'lineas.*.analisis' => 'nullable|string|max:60000',
            ], ['lineas.*.description.required' => 'Cada producto necesita una descripción.', 'lineas.*.qty.required' => 'Cada producto necesita una cantidad.', 'lineas.*.qty.gt' => 'La cantidad debe ser mayor que cero.']),
            'detalle' => $request->validate([
                'detalle' => 'nullable|string|max:65000',
                'enviar_correo' => 'nullable|boolean',
                'asunto_correo' => 'nullable|string|max:250',
                'incluir_firma' => 'nullable|boolean',
            ]),
        };

        try {
            $resultado = $this->servicio->guardar($this->usuario(), $sol, $seccion, $datos);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $sol = $this->servicio->solicitud($solicitud);

        return response()->json(['ok' => true, 'titulo' => $sol->ref, 'message' => $seccion === 'detalle' && ! empty($datos['enviar_correo']) ? 'Detalle guardado y correo enviado' : null] + ($resultado ?? []));
    }

    public function contactos(Request $request): JsonResponse
    {
        return response()->json(['items' => $this->servicio->contactos($request->integer('soc'))]);
    }

    public function productos(Request $request): JsonResponse
    {
        return response()->json(['items' => $this->servicio->productos((string) $request->query('q', ''))]);
    }

    /** Peso del cliente (solicitudes, cotizaciones, órdenes y montos) para la tarjeta de la ficha. */
    public function clienteResumen(Request $request, ActividadesService $actividades): JsonResponse
    {
        $soc = $request->integer('soc');
        abort_unless($soc > 0, 422, 'Cliente inválido.');

        $p = \Illuminate\Support\Facades\DB::getTablePrefix();
        $ultimas = array_map(fn ($s) => [
            'id' => (int) $s->rowid,
            'ref' => $s->ref,
            'fecha' => $s->fecha,
            'asunto' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags((string) $s->asunto))), 0, 90, '…'),
            'estado' => SolicitudesGridController::ESTADOS[(int) $s->status] ?? 'Estado '.$s->status,
        ], \Illuminate\Support\Facades\DB::select("SELECT rowid, ref, DATE(COALESCE(fecha_solicitud, date_creation)) fecha, asunto, status
            FROM {$p}solicitudes_solicitud WHERE fk_soc = ? AND status > 0 ORDER BY COALESCE(fecha_solicitud, date_creation) DESC, rowid DESC LIMIT 6", [$soc]));

        return response()->json(['resumen' => $actividades->resumenCliente($soc), 'ultimas' => $ultimas]);
    }

    /** Resumen por meses del cliente: el mismo que abre «Importancia del cliente» en el seguimiento. */
    public function clienteMeses(Request $request, ActividadesService $actividades): JsonResponse
    {
        $soc = $request->integer('soc');
        $nombre = $soc > 0 ? \Illuminate\Support\Facades\DB::table('societe')->where('rowid', $soc)->value('nom') : null;
        abort_unless($nombre, 422, 'Cliente inválido.');

        return response()->json(['cliente' => $nombre] + $actividades->mesesCliente($soc));
    }

    /** Resumen por producto: historial de compras/cotizaciones y dónde conseguirlo (IA). */
    public function inteligencia(Request $request, SolicitudProductosIaService $ia): JsonResponse
    {
        abort_unless($this->servicio->puedeEditar($this->usuario()), 403, 'No tienes permiso para esta acción.');
        $d = $request->validate([
            'ia' => 'nullable|boolean',
            'lineas' => 'required|array|min:1|max:8',
            'lineas.*.key' => 'required|string|max:20',
            'lineas.*.fk_product' => 'nullable|integer',
            'lineas.*.description' => 'required|string|max:2000',
            'lineas.*.model' => 'nullable|string|max:40',
            'lineas.*.qty' => 'nullable|numeric',
        ], ['lineas.required' => 'Agrega al menos un producto.', 'lineas.max' => 'Máximo 8 productos por consulta.']);

        return response()->json(['ok' => true] + $ia->analizar($d['lineas'], (bool) ($d['ia'] ?? true)));
    }

    /** Redacta el correo con IA a partir de la solicitud, un texto base (o plantilla) y las instrucciones del asesor. */
    public function redactarCorreo(Request $request, int $solicitud, SolicitudCorreoIaService $ia): JsonResponse
    {
        $sol = $this->cargar($solicitud);
        abort_unless($this->servicio->puedeEditar($this->usuario()), 403, 'No tienes permiso para esta acción.');
        $d = $request->validate([
            'lineas' => 'nullable|array|max:40',
            'lineas.*.description' => 'required|string|max:2000',
            'lineas.*.model' => 'nullable|string|max:40',
            'lineas.*.qty' => 'nullable|numeric',
            'base' => 'nullable|string|max:20000',
            'instrucciones' => 'nullable|string|max:1000',
        ]);
        try {
            $correo = $ia->redactar($this->usuario(), $sol, $d['lineas'] ?? [], $d['base'] ?? null, $d['instrucciones'] ?? null);
        } catch (IaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $correo);
    }

    public function cerrar(Request $request, int $solicitud): JsonResponse
    {
        $sol = $this->cargar($solicitud);
        abort_unless($this->servicio->puedeEditar($this->usuario()), 403, 'No tienes permiso para cerrar solicitudes.');
        $motivo = $request->validate(['motivo' => 'required|integer'], ['motivo.required' => 'Elige el motivo de cierre.'])['motivo'];

        return $this->accion(fn () => $this->servicio->cerrar($this->usuario(), $sol, (int) $motivo), 'Solicitud cerrada');
    }

    public function reabrir(int $solicitud): JsonResponse
    {
        $sol = $this->cargar($solicitud);
        abort_unless($this->servicio->puedeEditar($this->usuario()), 403, 'No tienes permiso para reabrir solicitudes.');

        return $this->accion(fn () => $this->servicio->reabrir($this->usuario(), $sol), 'Solicitud reabierta');
    }

    private function accion(callable $fn, string $mensaje): JsonResponse
    {
        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'message' => $mensaje]);
    }

    private function cargar(int $id): object
    {
        $sol = $this->servicio->solicitud($id);
        abort_unless($sol && $this->servicio->puedeVer($this->usuario(), $sol), 403, 'No tienes acceso a esta solicitud.');

        return $sol;
    }

    private function ficha(?object $sol): JsonResponse
    {
        $usuario = $this->usuario();
        $html = view('panel.solicitudes.partials._ficha', $this->servicio->datosFicha($sol) + [
            'sol' => $sol,
            'esNuevo' => $sol === null,
            'puedeEditar' => $this->servicio->puedeEditar($usuario) && (! $sol || (int) $sol->status === SolicitudFichaService::ACTIVO),
            'puedeCerrar' => $this->servicio->puedeEditar($usuario),
            'catalogos' => $this->servicio->catalogos($usuario),
            'estados' => SolicitudesGridController::ESTADOS,
            'color' => $this->dashboard->coloresEtapas()[1]['fondo'] ?? '#1e3a8a', // card «Solicitudes» del tablero
        ])->render();

        return response()->json(['html' => $html]);
    }

    /** @return array<string, mixed> */
    private function reglasGeneral(): array
    {
        return [
            'fk_soc' => 'required|integer|exists:societe,rowid',
            'contacto_id' => 'required|integer|exists:socpeople,rowid',
            'relacionados' => 'nullable|array',
            'relacionados.*' => 'integer',
            'fecha_solicitud' => 'required|date',
            'fecha_proximo_contacto' => 'nullable|date',
            'asunto' => 'required|string|max:150',
        ];
    }

    /** @return array<string, string> */
    private function atributos(): array
    {
        return ['fk_soc' => 'cliente', 'contacto_id' => 'contacto principal', 'fecha_solicitud' => 'fecha de la solicitud', 'fecha_proximo_contacto' => 'próximo contacto', 'asunto' => 'asunto'];
    }
}
