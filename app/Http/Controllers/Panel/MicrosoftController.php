<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Microsoft\MicrosoftGraphService;
use App\Services\Microsoft\MicrosoftSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Conexión de cada usuario con Office 365 (OAuth) y webhook de cambios de Outlook. */
class MicrosoftController extends Controller
{
    public function __construct(private MicrosoftGraphService $graph, private MicrosoftSyncService $sync)
    {
    }

    private function usuario()
    {
        return Auth::guard('web')->user();
    }

    public function estado(): JsonResponse
    {
        return response()->json($this->sync->estado((int) $this->usuario()->getKey()));
    }

    public function conectar(Request $request)
    {
        abort_unless($this->graph->configurado(), 503, 'Office 365 no está configurado en el servidor.');
        $state = Str::random(40);
        $request->session()->put('ms_state', $state);

        return redirect()->away($this->graph->urlAutorizacion($state));
    }

    public function callback(Request $request)
    {
        $volver = fn (string $r) => redirect()->route('panel.dashboard', ['ms' => $r]);
        $esperado = $request->session()->pull('ms_state');

        if ($request->filled('error')) {
            Log::info('Microsoft OAuth rechazado: '.$request->query('error_description'));

            return $volver('cancelado');
        }
        if (! $esperado || ! hash_equals($esperado, (string) $request->query('state')) || ! $request->filled('code')) {
            return $volver('error');
        }

        try {
            $t = $this->graph->canjear((string) $request->query('code'));
            $perfil = $this->graph->perfil($t['access_token']);
            $uid = (int) $this->usuario()->getKey();

            DB::table('panel_ms_cuentas')->updateOrInsert(['user_id' => $uid], [
                'ms_email' => $perfil['mail'] ?? $perfil['userPrincipalName'] ?? null,
                'ms_nombre' => $perfil['displayName'] ?? null,
                'access_token' => Crypt::encryptString($t['access_token']),
                'refresh_token' => Crypt::encryptString($t['refresh_token'] ?? ''),
                'expira_en' => now('UTC')->addSeconds((int) ($t['expires_in'] ?? 3600)),
                'estado' => 'activa', 'ultimo_error' => null,
                'lista_tareas_id' => null, 'suscripcion_id' => null, 'suscripcion_expira' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $cuentaNueva = $this->sync->cuenta($uid);
            $this->sync->asegurarSuscripcion($cuentaNueva);
            // Lo que ya tenías programado en el panel se envía a Outlook / To Do al conectar.
            $this->sync->enviarPendientes($cuentaNueva);
        } catch (Throwable $e) {
            report($e);

            return $volver('error');
        }

        return $volver('ok');
    }

    public function preferencias(Request $request): JsonResponse
    {
        $d = $request->validate(['calendario' => ['required', 'boolean'], 'tareas' => ['required', 'boolean'], 'teams' => ['required', 'boolean']]);
        $n = DB::table('panel_ms_cuentas')->where('user_id', $this->usuario()->getKey())
            ->update(['usar_calendario' => $d['calendario'], 'usar_tareas' => $d['tareas'], 'usar_teams' => $d['teams'], 'updated_at' => now()]);
        abort_unless($n >= 0, 500);

        return response()->json(['ok' => true] + $this->sync->estado((int) $this->usuario()->getKey()));
    }

    /** Sincronización manual (traer cambios de Outlook / To Do ahora). */
    public function sincronizar(): JsonResponse
    {
        $cuenta = $this->sync->cuenta((int) $this->usuario()->getKey());
        abort_unless($cuenta, 404, 'No tienes Office 365 conectado.');

        try {
            $r = $this->sync->sincronizarCuenta($cuenta);
        } catch (Throwable $e) {
            report($e);
            abort(502, 'No se pudo consultar Office 365.');
        }

        return response()->json(['ok' => true] + $r);
    }

    /** Eventos del calendario de Outlook en el rango visible del programador. */
    public function eventos(Request $request): JsonResponse
    {
        $d = $request->validate(['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde']]);
        $cuenta = $this->sync->cuenta((int) $this->usuario()->getKey());
        if (! $cuenta || ! $cuenta->usar_calendario) {
            return response()->json(['items' => []]);
        }
        $desde = \Illuminate\Support\Carbon::parse($d['desde'], 'America/Bogota');
        $hasta = \Illuminate\Support\Carbon::parse($d['hasta'], 'America/Bogota');
        abort_if($desde->diffInDays($hasta) > 62, 422, 'Rango demasiado grande.');

        try {
            return response()->json(['items' => $this->sync->eventosOutlook($cuenta, $desde, $hasta)]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['items' => [], 'error' => 'No se pudo leer el calendario de Outlook.']);
        }
    }

    public function desconectar(): JsonResponse
    {
        $uid = (int) $this->usuario()->getKey();
        $cuenta = DB::table('panel_ms_cuentas')->where('user_id', $uid)->first();
        if ($cuenta) {
            if ($cuenta->suscripcion_id && $cuenta->estado === 'activa') {
                try {
                    $this->graph->http($cuenta)->delete("/subscriptions/{$cuenta->suscripcion_id}");
                } catch (Throwable) {
                }
            }
            DB::table('panel_ms_cuentas')->where('id', $cuenta->id)->delete();
            DB::table('panel_ms_vinculos')->where('user_id', $uid)->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** Webhook público de Graph: valida la suscripción y dispara la sincronización de la cuenta afectada. */
    public function webhook(Request $request): Response
    {
        if ($token = $request->query('validationToken')) {
            return response((string) $token, 200, ['Content-Type' => 'text/plain']);
        }

        foreach ((array) $request->input('value', []) as $n) {
            $cuenta = DB::table('panel_ms_cuentas')->where('suscripcion_id', $n['subscriptionId'] ?? '')->where('estado', 'activa')->first();
            if (! $cuenta || ! hash_equals($this->sync->clientState($cuenta), (string) ($n['clientState'] ?? ''))) {
                continue;
            }
            // Una ráfaga de avisos (Outlook manda varios por cambio) se atiende una sola vez cada 15 s.
            if (Cache::add('ms-sync-'.$cuenta->id, 1, 15)) {
                dispatch(function () use ($cuenta) {
                    app(MicrosoftSyncService::class)->sincronizarCuenta($cuenta);
                })->afterResponse();
            }
        }

        return response('', 202);
    }
}
