<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Panel\PanelDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PanelDashboardController extends Controller
{
    public function __construct(private PanelDashboardService $service)
    {
    }

    public function index(Request $request): View
    {
        $user = Auth::guard('web')->user();

        return view('panel.dashboard', [
            'user' => $user,
            // Negociadores (grupos de Olimpiadas): conservan la cabecera (saludo, fechas y periodo) pero sin los insights ni los KPIs generales.
            'soloComercial' => app(\App\Services\Panel\VistaComercialService::class)->activa($user, $request->boolean('completo')),
            'inicial' => $this->service->resumen('30', null),
            'periodos' => PanelDashboardService::PERIODOS,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $datos = $this->service->resumen(
            (string) $request->query('periodo', '30'),
            $request->integer('vendedor') ?: null,
        );

        return response()->json($datos);
    }

    public function etapas(Request $request): JsonResponse
    {
        return response()->json($this->service->etapas(
            Auth::guard('web')->user(),
            (string) $request->query('periodo', '30'),
            $request->query('alcance', 'todos') !== 'mios',
        ));
    }

    /** Línea de proceso comercial (negociadores): total y fecha más antigua por etapa, desde el año configurado. */
    public function proceso(Request $request): JsonResponse
    {
        return response()->json(app(\App\Services\Panel\ProcesoComercialService::class)->datos(
            Auth::guard('web')->user(),
            $request->query('alcance', 'todos') !== 'mios',
        ) + ['colores' => $this->service->coloresEtapas()]);
    }

    /** Documentos de una etapa de la línea de proceso, con su último seguimiento. */
    public function procesoDocumentos(Request $request, string $etapa): JsonResponse
    {
        return response()->json(app(\App\Services\Panel\ProcesoComercialService::class)->documentos(
            Auth::guard('web')->user(),
            $request->query('alcance', 'todos') !== 'mios',
            $etapa,
        ));
    }

    public function documentosEtapa(Request $request, string $etapa): JsonResponse
    {
        return response()->json($this->service->documentosEtapa(
            Auth::guard('web')->user(),
            $etapa,
            (string) $request->query('periodo', '30'),
            $request->query('alcance', 'todos') !== 'mios',
            (string) $request->query('q', ''),
            40,
            in_array($request->query('nivel'), ['mora', 'riesgo', 'aldia'], true) ? (string) $request->query('nivel') : '',
        ));
    }

    public function leyenda(Request $request): JsonResponse
    {
        return response()->json($this->service->metricasLeyenda(
            Auth::guard('web')->user(),
            (string) $request->query('periodo', '30'),
            $request->query('alcance', 'todos') !== 'mios',
        ));
    }

    /** Colores vigentes de las 5 cards (4 etapas + tasa de aprobación), con sus valores de fábrica para "Restablecer". */
    public function colores(): JsonResponse
    {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403, 'Solo un administrador puede cambiar los colores.');

        return response()->json($this->payloadColores());
    }

    public function guardarColores(Request $request): JsonResponse
    {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403, 'Solo un administrador puede cambiar los colores.');

        $hex = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $datos = $request->validate([
            'colores' => ['present', 'array'],
            'colores.*' => ['array'],
            'colores.*.fondo' => $hex,
            'colores.*.texto' => $hex,
            'colores.*.acento' => $hex,
        ], ['colores.*' => 'Color inválido: usa el formato #RRGGBB.']);

        $limpios = [];
        foreach ($datos['colores'] as $n => $c) {
            if (! in_array((int) $n, [1, 2, 3, 4, 5], true)) {
                continue;
            }
            $c = array_filter(array_map(fn ($v) => $v ? strtolower($v) : null, array_intersect_key($c, array_flip(['fondo', 'texto', 'acento']))));
            if ($c) {
                $limpios[(int) $n] = $c;
            }
        }
        $this->service->guardarColoresPersonalizados($limpios);

        return response()->json($this->payloadColores());
    }

    private function payloadColores(): array
    {
        return [
            'colores' => $this->service->coloresEtapas() + [5 => ['fondo' => $this->service->colorTasa()]],
            'personalizados' => (object) $this->service->coloresPersonalizados(),
        ];
    }
}
