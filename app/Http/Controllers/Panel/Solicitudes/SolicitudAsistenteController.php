<?php

namespace App\Http\Controllers\Panel\Solicitudes;

use App\Http\Controllers\Controller;
use App\Services\Ia\IaException;
use App\Services\Ia\SolicitudCorreoIaService;
use App\Services\Ia\SolicitudPromptsService;
use App\Services\Panel\SolicitudAsistenteService;
use App\Services\Panel\SolicitudFichaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Asistente «Crear con asistente»: cliente, historial del elemento, análisis con IA y su instrucción editable. */
class SolicitudAsistenteController extends Controller
{
    public function __construct(private SolicitudAsistenteService $asistente, private SolicitudFichaService $ficha, private SolicitudPromptsService $prompts)
    {
    }

    private function usuario()
    {
        return Auth::guard('web')->user();
    }

    private function permitir(): void
    {
        abort_unless($this->ficha->puedeEditar($this->usuario()), 403, 'No tienes permiso para crear solicitudes.');
    }

    public function clientes(Request $request): JsonResponse
    {
        $this->permitir();

        return response()->json(['items' => $this->asistente->clientes((string) $request->query('q', ''))]);
    }

    public function cliente(int $cliente): JsonResponse
    {
        $this->permitir();
        $datos = $this->asistente->cliente($this->usuario(), $cliente);
        abort_unless($datos, 404, 'Cliente no encontrado.');

        return response()->json($datos);
    }

    /** Efectividad del cliente (solicitud → cotización → orden) en el periodo elegido. */
    public function efectividad(Request $request, int $cliente): JsonResponse
    {
        $this->permitir();
        $d = $request->validate([
            'periodo' => 'nullable|in:1m,3m,6m,rango',
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => 'nullable|date_format:Y-m-d',
        ]);

        return response()->json($this->asistente->efectividad($cliente, $d['periodo'] ?? '3m', $d['desde'] ?? null, $d['hasta'] ?? null));
    }

    /** Siguiente página de las piezas que ya ha pedido, cotizado o comprado el cliente. */
    public function piezasCliente(Request $request, int $cliente): JsonResponse
    {
        $this->permitir();
        $desde = max(0, min(500, (int) $request->query('desde', 0)));

        return response()->json($this->asistente->piezasCliente($this->usuario(), $cliente, $desde, 5));
    }

    /** Dónde aparecen ya, en el historial del cliente, las piezas que se están buscando. */
    public function coincidencias(Request $request, int $cliente): JsonResponse
    {
        $this->permitir();
        $d = $request->validate([
            'piezas' => 'required|array|min:1|max:10',
            'piezas.*.id' => 'required|string|max:20',
            'piezas.*.referencia' => 'nullable|string|max:100',
            'piezas.*.fk_product' => 'nullable|integer',
        ]);

        return response()->json(['coincidencias' => $this->asistente->coincidencias($this->usuario(), $cliente, $d['piezas'])]);
    }

    public function historial(Request $request): JsonResponse
    {
        $this->permitir();
        $d = $request->validate(['fk_product' => 'nullable|integer', 'referencia' => 'nullable|string|max:100']);

        return response()->json($this->asistente->historial($d['fk_product'] ?? null, $d['referencia'] ?? null));
    }

    public function analizar(Request $request): JsonResponse
    {
        $this->permitir();
        $d = $request->validate([
            'referencia' => 'nullable|string|max:100',
            'nombre' => 'nullable|string|max:200',
            'descripcion' => 'nullable|string|max:3000',
            'cantidad' => 'nullable|numeric',
            'fk_product' => 'nullable|integer',
            'cliente' => 'nullable|string|max:200',
            'foto' => 'nullable|string|max:4000000',
        ]);
        if (blank($d['referencia'] ?? null) && blank($d['nombre'] ?? null) && blank($d['descripcion'] ?? null) && blank($d['foto'] ?? null)) {
            return response()->json(['message' => 'Indica la referencia, el nombre, una descripción o una foto del elemento.'], 422);
        }

        $foto = null;
        if (! empty($d['foto'])) {
            if (! preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $d['foto'], $m)) {
                return response()->json(['message' => 'La foto debe ser una imagen JPG, PNG o WEBP.'], 422);
            }
            $foto = ['mime' => $m[1], 'data' => $m[2]];
        }

        set_time_limit(150); // la búsqueda web de la IA puede tardar
        $historial = $this->asistente->historial($d['fk_product'] ?? null, $d['referencia'] ?? null);
        try {
            $analisis = $this->asistente->analizar($d, $foto, $historial);
        } catch (IaException $e) {
            return response()->json(['message' => $e->getMessage(), 'historial' => $historial], 422);
        }

        return response()->json(['ok' => true, 'analisis' => $analisis, 'historial' => $historial]);
    }

    /** Texto libre del asesor (escrito o dictado) convertido en datos de la solicitud. */
    public function interpretar(Request $request): JsonResponse
    {
        $this->permitir();
        $d = $request->validate([
            'texto' => 'required|string|max:4000',
            'estado' => 'nullable|array',
            'estado.cliente' => 'nullable|string|max:200',
            'estado.contacto' => 'nullable|string|max:200',
            'estado.elementos' => 'nullable|array|max:20',
            'estado.elementos.*.referencia' => 'nullable|string|max:100',
            'estado.elementos.*.nombre' => 'nullable|string|max:200',
        ], ['texto.required' => 'Escribe o dicta lo que necesitas.']);
        set_time_limit(60);
        try {
            return response()->json(['ok' => true] + $this->asistente->interpretar($d['texto'], $d['estado'] ?? []));
        } catch (IaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No pude procesar el mensaje ('.class_basename($e).': '.mb_substr($e->getMessage(), 0, 200).')'], 500);
        }
    }

    /** Plantillas, firma y remitente para el paso del correo. */
    public function correoContexto(): JsonResponse
    {
        $this->permitir();

        return response()->json($this->asistente->contextoCorreo($this->usuario()));
    }

    /**
     * Redacta el correo con IA antes de que exista la solicitud: la referencia queda como __REF__ (se reemplaza al crearla)
     * y la firma no la escribe la IA porque se añade sola.
     */
    public function correoRedactar(Request $request, SolicitudCorreoIaService $ia): JsonResponse
    {
        $this->permitir();
        $d = $request->validate([
            'fk_soc' => 'required|integer|exists:societe,rowid',
            'contacto_id' => 'nullable|integer',
            'asunto' => 'nullable|string|max:250',
            'lineas' => 'required|array|min:1|max:40',
            'lineas.*.description' => 'required|string|max:2000',
            'lineas.*.model' => 'nullable|string|max:40',
            'lineas.*.qty' => 'nullable|numeric',
            'base' => 'nullable|string|max:20000',
            'instrucciones' => 'nullable|string|max:1000',
        ]);
        $sol = (object) [
            'ref' => '__REF__',
            'cliente' => \Illuminate\Support\Facades\DB::table('societe')->where('rowid', $d['fk_soc'])->value('nom'),
            'fk_soc' => $d['fk_soc'],
            'contacto_id' => $d['contacto_id'] ?? 0,
            'asunto' => $d['asunto'] ?? '',
        ];
        set_time_limit(90);
        try {
            return response()->json(['ok' => true] + $ia->redactar($this->usuario(), $sol, $d['lineas'], $d['base'] ?? null, $d['instrucciones'] ?? null, true));
        } catch (IaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function prompt(Request $request): JsonResponse
    {
        return response()->json($this->datosPrompt($this->clavePrompt($request)));
    }

    public function guardarPrompt(Request $request): JsonResponse
    {
        abort_unless($this->prompts->puedeEditar($this->usuario()), 403, 'No tienes permiso para editar las instrucciones de la IA.');
        $d = $request->validate(['texto' => 'nullable|string|max:6000']);
        $clave = $this->clavePrompt($request);
        $this->prompts->guardar([$clave => $d['texto'] ?? '']);

        return response()->json(['ok' => true] + $this->datosPrompt($clave));
    }

    /** Instrucciones editables desde el asistente: el análisis de la pieza y el correo al cliente. */
    private function clavePrompt(Request $request): string
    {
        $clave = (string) ($request->input('clave') ?: $request->query('clave', 'asistente'));
        abort_unless(in_array($clave, ['asistente', 'correo'], true), 422, 'Instrucción inválida.');

        return $clave;
    }

    private function datosPrompt(string $clave): array
    {
        $p = $this->prompts->todos()[$clave];

        return ['clave' => $clave, 'texto' => $p['texto'], 'defecto' => $p['defecto'], 'personalizado' => $p['personalizado'], 'formato' => $p['formato'], 'puedeEditar' => $this->prompts->puedeEditar($this->usuario())];
    }
}
