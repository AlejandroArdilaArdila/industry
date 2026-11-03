<?php

namespace App\Http\Controllers\Panel\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Perfil\PerfilController;
use App\Services\Ia\AsistenteTercerosService;
use App\Services\Ia\IaClient;
use App\Services\Ia\IaConfigService;
use App\Services\Ia\IaException;
use App\Services\Ia\SolicitudPromptsService;
use App\Services\Panel\ProcesoComercialService;
use App\Services\Panel\VistaComercialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Configuración del panel (solo administradores): perfil y parámetros de IA por módulo. */
class ConfiguracionController extends Controller
{
    public function __construct(
        private IaConfigService $ia,
        private AsistenteTercerosService $terceros,
    ) {
    }

    private function admin(): void
    {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403, 'Solo un administrador puede abrir la configuración.');
    }

    public function index(): View
    {
        $this->admin();

        return view('panel.configuracion.index', [
            'perfil' => app(PerfilController::class)->datosVista(),
            'ia' => $this->ia->publica(),
            'panel' => $this->panelDatos(),
            'terceros' => $this->terceros->config(),
            'promptsSolicitud' => app(SolicitudPromptsService::class)->todos(),
            'catalogoTerceros' => $this->terceros->catalogoPublico(),
            'tiposDato' => AsistenteTercerosService::TIPOS,
            'urls' => [
                'panel' => route('panel.configuracion.panel'),
                'proceso' => route('panel.configuracion.proceso'),
                'ia' => route('panel.configuracion.ia'),
                'probar' => route('panel.configuracion.ia.probar'),
                'terceros' => route('panel.configuracion.ia.terceros'),
                'promptsSolicitud' => route('panel.configuracion.ia.solicitudes'),
                'tercerosRestablecer' => route('panel.configuracion.ia.terceros.restablecer'),
            ],
        ]);
    }

    private function panelDatos(): array
    {
        $v = app(VistaComercialService::class);
        $yo = Auth::guard('web')->user();

        $pr = app(ProcesoComercialService::class);

        return ['proceso' => $pr->config(), 'procesoDefectos' => $pr->coloresPorDefecto(), 'semaforo' => $pr->colores(), 'fuentes' => $pr->catalogo(), 'usuarios' => $v->usuarios(), 'completos' => $v->usuariosCompletos(), 'grupos' => $v->grupos(), 'misGrupos' => $v->gruposDe($yo), 'soyAdmin' => (bool) $yo->isAdmin(), 'activaParaMi' => $v->activa($yo)];
    }

    /** Usuarios que siempre ven el panel completo aunque pertenezcan a un grupo de negociadores. */
    public function guardarPanel(Request $request): JsonResponse
    {
        $this->admin();
        $d = $request->validate(['usuarios' => ['present', 'array'], 'usuarios.*' => ['integer']]);
        app(VistaComercialService::class)->guardarUsuariosCompletos($d['usuarios']);

        return response()->json(['ok' => true, 'panel' => $this->panelDatos()]);
    }

    /** Parametrización de la línea de proceso comercial: fuente, estados y días verde/amarillo/rojo por etapa. */
    public function guardarProceso(Request $request): JsonResponse
    {
        $this->admin();
        $request->validate([
            'etapas' => ['required', 'array'],
            'etapas.*.clave' => ['required', 'string'],
            'etapas.*.fuente' => ['nullable', 'in:'.implode(',', array_keys(ProcesoComercialService::FUENTES))],
            'etapas.*.estados' => ['nullable', 'array'],
            'etapas.*.verde' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'etapas.*.amarillo' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'etapas.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'semaforo' => ['nullable', 'array'],
            'semaforo.*' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $pr = app(ProcesoComercialService::class);
        $request->boolean('restablecer') ? $pr->restablecer() : $pr->guardar($request->input('etapas'), $request->input('semaforo'));

        return response()->json(['ok' => true, 'panel' => $this->panelDatos()]);
    }

    /** Instrucciones editables de la IA de solicitudes; un texto vacío vuelve al por defecto. */
    public function guardarPromptsSolicitud(Request $request): JsonResponse
    {
        $this->admin();
        $d = $request->validate([
            'productos' => ['nullable', 'string', 'max:6000'],
            'correo' => ['nullable', 'string', 'max:6000'],
        ]);
        $servicio = app(SolicitudPromptsService::class);
        $servicio->guardar($d);

        return response()->json(['ok' => true, 'prompts' => $servicio->todos()]);
    }

    public function guardarIa(Request $request): JsonResponse
    {
        $this->admin();
        $datos = $request->validate([
            'predeterminado' => ['required', 'in:'.implode(',', array_keys(IaConfigService::PROVEEDORES))],
            'proveedores' => ['required', 'array'],
            'proveedores.*.modelo' => ['nullable', 'string', 'max:80'],
            'proveedores.*.token' => ['nullable', 'string', 'max:300'],
            'proveedores.*.borrar_token' => ['nullable', 'boolean'],
        ]);
        $this->ia->guardar($datos);

        return response()->json(['ok' => true, 'ia' => $this->ia->publica()]);
    }

    /** Prueba el token guardado con una consulta mínima. */
    public function probarIa(Request $request, IaClient $cliente): JsonResponse
    {
        $this->admin();
        $p = $request->validate(['proveedor' => ['required', 'in:'.implode(',', array_keys(IaConfigService::PROVEEDORES))]])['proveedor'];
        try {
            $r = $cliente->chat($p, 'Responde solo con la palabra OK.', [['role' => 'user', 'content' => 'Prueba de conexión.']], false, false);

            return response()->json(['ok' => true, 'mensaje' => 'Conexión correcta con '.IaConfigService::PROVEEDORES[$p]['nombre'].' (respondió: '.mb_substr($r, 0, 40).').']);
        } catch (IaException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }
    }

    public function guardarTerceros(Request $request): JsonResponse
    {
        $this->admin();
        $request->validate([
            'campos' => ['required', 'array', 'min:1'],
            'campos.*.clave' => ['required', 'in:'.implode(',', array_keys($this->terceros->catalogo()))],
            'campos.*.tipo' => ['nullable', 'string', 'max:20'],
            'campos.*.min' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'campos.*.max' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'campos.*.patron' => ['nullable', 'string', 'max:200', function ($attr, $valor, $fail) {
                if (! AsistenteTercerosService::patronValido((string) $valor)) {
                    $fail('El patrón (expresión regular) no es válido.');
                }
            }],
            'campos.*.instrucciones' => ['nullable', 'string', 'max:1000'],
            'campos.*.validacion' => ['nullable', 'string', 'max:1000'],
            'campos.*.etiqueta' => ['nullable', 'string', 'max:80'],
            'rol' => ['nullable', 'string', 'max:2000'],
            'tono' => ['nullable', 'string', 'max:500'],
            'saludo' => ['nullable', 'string', 'max:500'],
            'reglas_extra' => ['nullable', 'string', 'max:3000'],
            'proveedor' => ['nullable', 'string'],
        ]);
        $this->terceros->guardarConfig($request->all());

        return response()->json(['ok' => true, 'terceros' => $this->terceros->config()]);
    }

    public function restablecerTerceros(): JsonResponse
    {
        $this->admin();
        $this->terceros->restablecerConfig();

        return response()->json(['ok' => true, 'terceros' => $this->terceros->config()]);
    }
}
