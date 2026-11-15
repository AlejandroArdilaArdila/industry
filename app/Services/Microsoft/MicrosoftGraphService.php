<?php

namespace App\Services\Microsoft;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Cliente mínimo de Microsoft Graph: OAuth 2.0 (código de autorización) y llamadas con renovación automática del token. */
class MicrosoftGraphService
{
    public const GRAPH = 'https://graph.microsoft.com/v1.0';

    public const SCOPES = 'offline_access User.Read Calendars.ReadWrite Tasks.ReadWrite';

    /** Zona horaria de Bogotá en nomenclatura de Windows (la que usa Graph). */
    public const TZ_MS = 'SA Pacific Standard Time';

    public function configurado(): bool
    {
        return filled(config('services.microsoft.client_id')) && filled(config('services.microsoft.client_secret')) && filled($this->redirect());
    }

    public function redirect(): ?string
    {
        return config('services.microsoft.redirect') ?: (config('app.url') ? rtrim(config('app.url'), '/').'/panel/microsoft/callback' : null);
    }

    private function login(): string
    {
        return 'https://login.microsoftonline.com/'.(config('services.microsoft.tenant') ?: 'organizations').'/oauth2/v2.0';
    }

    public function urlAutorizacion(string $state): string
    {
        return $this->login().'/authorize?'.http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirect(),
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /** Canjea el código por tokens. */
    public function canjear(string $codigo): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $codigo, 'redirect_uri' => $this->redirect(), 'scope' => self::SCOPES]);
    }

    private function token(array $datos): array
    {
        $r = Http::asForm()->timeout(20)->post($this->login().'/token', $datos + [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
        ]);
        if (! $r->successful()) {
            throw new RuntimeException($r->json('error_description') ?: 'Microsoft rechazó la autenticación ('.$r->status().').');
        }

        return $r->json();
    }

    /** Access token vigente de la cuenta; lo renueva con el refresh token si está por vencer. */
    public function accessToken(object $cuenta): string
    {
        if ($cuenta->expira_en && Carbon::parse($cuenta->expira_en, 'UTC')->gt(now('UTC')->addMinutes(2))) {
            return Crypt::decryptString($cuenta->access_token);
        }

        try {
            $t = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => Crypt::decryptString($cuenta->refresh_token), 'scope' => self::SCOPES]);
        } catch (RuntimeException $e) {
            DB::table('panel_ms_cuentas')->where('id', $cuenta->id)->update(['estado' => 'reconectar', 'ultimo_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
            throw $e;
        }

        $this->guardarTokens((int) $cuenta->id, $t);

        return $t['access_token'];
    }

    public function guardarTokens(int $cuentaId, array $t): void
    {
        $datos = ['access_token' => Crypt::encryptString($t['access_token']), 'expira_en' => now('UTC')->addSeconds((int) ($t['expires_in'] ?? 3600)), 'estado' => 'activa', 'ultimo_error' => null, 'updated_at' => now()];
        if (! empty($t['refresh_token'])) {
            $datos['refresh_token'] = Crypt::encryptString($t['refresh_token']);
        }
        DB::table('panel_ms_cuentas')->where('id', $cuentaId)->update($datos);
    }

    public function http(object $cuenta): PendingRequest
    {
        return Http::withToken($this->accessToken($cuenta))->baseUrl(self::GRAPH)->acceptJson()->timeout(25)
            ->withHeaders(['Prefer' => 'outlook.timezone="'.self::TZ_MS.'"']);
    }

    /** Perfil con el token recién obtenido (antes de tener cuenta guardada). */
    public function perfil(string $accessToken): array
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(20)->get(self::GRAPH.'/me?$select=displayName,mail,userPrincipalName')->throw()->json();
    }

    public function revisar(Response $r): Response
    {
        if ($r->failed() && $r->status() !== 404) {
            throw new RuntimeException('Graph '.$r->status().': '.($r->json('error.message') ?: $r->body()));
        }

        return $r;
    }
}
