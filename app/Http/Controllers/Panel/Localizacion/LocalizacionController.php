<?php

namespace App\Http\Controllers\Panel\Localizacion;

use App\Http\Controllers\Controller;
use App\Services\Ia\IaException;
use App\Services\Ia\LocalizacionCorreoIaService;
use App\Services\Ia\SolicitudPromptsService;
use App\Services\Panel\LocalizacionService;
use App\Services\Panel\SolicitudAsistenteService;
use App\Services\Panel\SolicitudFichaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;

/** Localización: pedir cotización de los productos de una solicitud a uno o varios proveedores. */
class LocalizacionController extends Controller
{
    public function __construct(private LocalizacionService $servicio, private SolicitudFichaService $ficha, private SolicitudPromptsService $prompts)
    {
    }

    private function usuario()
    {
        return Auth::guard('web')->user();
    }

    /** Ver el módulo es como ver solicitudes (cada quien solo las suyas); enviar correos y redactar exige poder escribirlas. */
    private function permitir(bool $escribir = false): void
    {
        abort_if($escribir && ! $this->ficha->puedeEditar($this->usuario()), 403, 'No tienes permiso para gestionar solicitudes.');
    }

    private function solicitud(int $id): object
    {
        $sol = $this->ficha->solicitud($id);
        abort_unless($sol, 404, 'Solicitud no encontrada.');
        abort_unless($this->ficha->puedeVer($this->usuario(), $sol), 403, 'No tienes acceso a esta solicitud.');

        return $sol;
    }

    public function index(): View
    {
        $this->permitir();

        return view('panel.localizacion.localizacion', ['tablero' => $this->servicio->definicion()]);
    }

    public function listado(Request $request): JsonResponse
    {
        $this->permitir();
        $estado = (int) $request->query('estado', 0);

        return response()->json($this->servicio->listado($this->usuario(), (string) $request->query('q', ''), $estado ?: null, max(0, (int) $request->query('skip', 0))));
    }

    /** Una columna del tablero de localización. */
    public function columna(Request $request, string $columna): JsonResponse
    {
        $this->permitir();
        abort_unless(isset(LocalizacionService::COLUMNAS[$columna]), 404);
        $d = $request->validate(['q' => 'nullable|string|max:100', 'estado' => 'nullable|integer', 'skip' => 'nullable|integer|min:0']);

        return response()->json($this->servicio->columna($this->usuario(), $columna, $d['q'] ?? null, isset($d['estado']) ? (int) $d['estado'] : null, (int) ($d['skip'] ?? 0)));
    }

    public function solicitudDetalle(int $solicitud): JsonResponse
    {
        $this->permitir();
        $this->solicitud($solicitud);

        return response()->json($this->servicio->detalle($solicitud));
    }

    /** Proveedores que ya vendieron los productos (resaltados) para proponerlos al abrir el paso. */
    public function sugeridos(Request $request): JsonResponse
    {
        $this->permitir();
        $d = $request->validate(['productos' => 'nullable|array|max:60', 'productos.*' => 'integer']);

        return response()->json(['items' => $this->servicio->sugeridos($d['productos'] ?? [])]);
    }

    public function buscar(Request $request): JsonResponse
    {
        $this->permitir();
        $productos = array_map('intval', array_filter(explode(',', (string) $request->query('productos', ''))));

        return response()->json(['items' => $this->servicio->buscar((string) $request->query('q', ''), $productos)]);
    }

    public function proveedor(Request $request, int $proveedor): JsonResponse
    {
        $this->permitir();
        $productos = array_map('intval', array_filter(explode(',', (string) $request->query('productos', ''))));
        $datos = $this->servicio->proveedor($proveedor, $productos);
        abort_unless($datos, 404, 'Proveedor no encontrado.');

        return response()->json($datos);
    }

    /** Correo de solicitud de cotización para un proveedor, redactado con IA. */
    public function redactar(Request $request, int $solicitud, LocalizacionCorreoIaService $ia): JsonResponse
    {
        $this->permitir(true);
        $sol = $this->solicitud($solicitud);
        $d = $request->validate([
            'proveedor.nombre' => 'required|string|max:200',
            'proveedor.contacto' => 'nullable|string|max:200',
            'proveedor.pais' => 'nullable|string|max:80',
            'proveedor.comprado' => 'nullable|array|max:30',
            'proveedor.comprado.*' => 'nullable|string|max:200',
            'piezas' => 'required|array|min:1|max:60',
            'piezas.*.descripcion' => 'required|string|max:1000',
            'piezas.*.ref' => 'nullable|string|max:100',
            'piezas.*.modelo' => 'nullable|string|max:100',
            'piezas.*.cantidad' => 'nullable|numeric',
            'idioma' => 'required|in:es,en',
            'instrucciones' => 'nullable|string|max:1000',
        ]);
        try {
            return response()->json($ia->redactar($this->usuario(), (string) $sol->ref, $d['proveedor'], $d['piezas'], $d['idioma'], $d['instrucciones'] ?? null));
        } catch (IaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Firma del asesor y plantilla de correo para armar la vista previa. */
    public function contexto(SolicitudAsistenteService $asistente): JsonResponse
    {
        $this->permitir();

        return response()->json(['firma' => $this->ficha->firmaHtml($this->usuario()), 'de' => $asistente->contextoCorreo($this->usuario())['de'] ?? null]);
    }

    public function enviar(Request $request, int $solicitud): JsonResponse
    {
        $this->permitir(true);
        $sol = $this->solicitud($solicitud);
        $d = $request->validate([
            'envios' => 'required|array|min:1|max:30',
            'envios.*.proveedor_id' => 'nullable|integer',
            'envios.*.nombre' => 'required|string|max:200',
            'envios.*.correos' => 'required|array|min:1|max:10',
            'envios.*.correos.*' => 'required|email|max:120',
            'envios.*.asunto' => 'required|string|max:250',
            'envios.*.cuerpo' => 'required|string|max:60000',
            'envios.*.idioma' => 'nullable|in:es,en',
            'incluir_firma' => 'nullable|boolean',
        ]);
        $firma = ! empty($d['incluir_firma']) ? $this->ficha->firmaHtml($this->usuario()) : '';
        $envios = array_map(function ($e) use ($firma) {
            $cuerpo = strip_tags($e['cuerpo'], '<p><br><div><span><b><strong><i><em><u><ul><ol><li><a><h3><h4><table><thead><tbody><tr><td><th>');
            $e['cuerpo'] = $firma !== '' ? $cuerpo.'<br><br>'.$firma : $cuerpo;

            return $e;
        }, $d['envios']);
        try {
            return response()->json($this->servicio->enviar($this->usuario(), $sol, $envios));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function prompt(): JsonResponse
    {
        $this->permitir();

        return response()->json(['puedeEditar' => $this->prompts->puedeEditar($this->usuario())] + $this->prompts->todos()['localizacion']);
    }

    public function guardarPrompt(Request $request): JsonResponse
    {
        $this->permitir(true);
        abort_unless($this->prompts->puedeEditar($this->usuario()), 403);
        $d = $request->validate(['texto' => 'nullable|string|max:6000']);
        $this->prompts->guardar(['localizacion' => $d['texto'] ?? '']);

        return response()->json(['ok' => true] + $this->prompts->todos()['localizacion']);
    }
}
