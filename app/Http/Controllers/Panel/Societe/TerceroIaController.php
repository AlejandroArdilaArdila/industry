<?php

namespace App\Http\Controllers\Panel\Societe;

use App\Http\Controllers\Controller;
use App\Services\Ia\AsistenteTercerosService;
use App\Services\Ia\IaException;
use App\Services\Societe\TerceroFichaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** "Creación con IA" del listado de terceros: conversación guiada por el prompt que parametriza el administrador. */
class TerceroIaController extends Controller
{
    public function __construct(private AsistenteTercerosService $asistente, private TerceroFichaService $ficha)
    {
    }

    private function permitir(): \App\Models\DolibarrUser
    {
        $u = Auth::guard('web')->user();
        abort_unless($this->ficha->puedeEditar($u), 403, 'No tienes permiso para crear terceros.');

        return $u;
    }

    public function inicio(): JsonResponse
    {
        $this->permitir();

        return response()->json($this->asistente->inicio());
    }

    public function mensaje(Request $request): JsonResponse
    {
        $this->permitir();
        $d = $request->validate([
            'historial' => ['required', 'array', 'min:1', 'max:60'],
            'historial.*.role' => ['required', 'in:user,assistant'],
            'historial.*.content' => ['required', 'string', 'max:4000'],
            'datos' => ['nullable', 'array'],
        ]);

        try {
            return response()->json($this->asistente->turno($d['historial'], (array) ($d['datos'] ?? [])));
        } catch (IaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Lee un RUT adjunto (PDF o imagen), valida sus datos y continúa la conversación. */
    public function rut(Request $request): JsonResponse
    {
        $this->permitir();
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
            'historial' => ['required', 'string'],
            'datos' => ['nullable', 'string'],
        ], ['archivo.max' => 'El archivo no puede superar 8 MB.', 'archivo.mimes' => 'Adjunta el RUT en PDF o imagen (JPG, PNG).']);

        $historial = json_decode((string) $request->input('historial'), true);
        $datos = json_decode((string) $request->input('datos', '{}'), true);
        if (! is_array($historial) || ! $historial || ! is_array($datos ?? [])) {
            return response()->json(['message' => 'Solicitud inválida.'], 422);
        }
        $historial = array_values(array_filter(array_map(fn ($m) => [
            'role' => ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
            'content' => mb_substr((string) ($m['content'] ?? ''), 0, 4000),
        ], array_slice($historial, -30)), fn ($m) => $m['content'] !== ''));
        $historial[] = ['role' => 'user', 'content' => 'Adjunto el RUT.'];

        $f = $request->file('archivo');
        $mime = $f->getMimeType() === 'application/pdf' ? 'application/pdf' : (in_array($f->getMimeType(), ['image/jpeg', 'image/png', 'image/webp']) ? $f->getMimeType() : null);
        if (! $mime) {
            return response()->json(['message' => 'Adjunta el RUT en PDF o imagen (JPG, PNG).'], 422);
        }

        try {
            return response()->json($this->asistente->desdeRut(
                ['mime' => $mime, 'data' => base64_encode((string) file_get_contents($f->getRealPath())), 'nombre' => $f->getClientOriginalName()],
                $historial,
                $datos ?? [],
            ));
        } catch (IaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function crear(Request $request): JsonResponse
    {
        $usuario = $this->permitir();
        $d = $request->validate(['datos' => ['required', 'array']]);
        $tercero = $this->asistente->crear($d['datos'], $usuario);

        return response()->json([
            'ok' => true,
            'message' => 'Tercero creado',
            'tercero' => ['rowid' => (int) $tercero->rowid, 'nom' => $tercero->nom, 'siren' => $tercero->siren],
        ]);
    }
}
