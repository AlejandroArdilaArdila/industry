<?php

namespace App\Auth;

use App\Models\DolibarrUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

/**
 * Guard que autentica al usuario a partir del header 'DOLAPIKEY'.
 *
 * La APIKEY es el api_key que Dolibarr guarda en llx_user (devuelto
 * descifrado en el login como 'APIKEY'). Se compara contra el valor
 * almacenado, ya sea en texto plano o cifrado con el IV fijo estilo
 * Dolibarr (dolEncrypt(..., 'dolibarr')), lo que permite ubicar al
 * usuario sin descifrar toda la tabla.
 */
class ApiKeyGuard implements Guard
{
    protected ?Authenticatable $user = null;

    public function __construct(
        protected UserProvider $provider,
        protected Request $request,
    ) {
    }

    /**
     * Nombre del header donde viaja la APIKEY.
     */
    public const HEADER = 'DOLAPIKEY';

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $apiKey = $this->request->header(self::HEADER);

        if (!$apiKey) {
            return null;
        }

        return $this->user = DolibarrUser::query()
            ->where('statut', 1)
            ->whereIn('entity', [0, 1])
            ->where(function ($q) use ($apiKey) {
                $q->where('api_key', $apiKey)
                    ->orWhere('api_key', $this->cifrarApiKeyEstiloDolibarr($apiKey));
            })
            ->first();
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id()
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return isset($credentials[self::HEADER])
            && $this->userFromApiKey($credentials[self::HEADER]) !== null;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
    }

    /**
     * Resuelve un DolibarrUser a partir de una APIKEY dada.
     */
    protected function userFromApiKey(string $apiKey): ?Authenticatable
    {
        return DolibarrUser::query()
            ->where('statut', 1)
            ->whereIn('entity', [0, 1])
            ->where(function ($q) use ($apiKey) {
                $q->where('api_key', $apiKey)
                    ->orWhere('api_key', $this->cifrarApiKeyEstiloDolibarr($apiKey));
            })
            ->first();
    }

    /**
     * Replica dolEncrypt($apiKey, '', '', 'dolibarr'): Dolibarr usa un IV
     * fijo (derivado del seed 'dolibarr') para poder buscar el usuario
     * por api_key sin tener que desencriptar toda la tabla.
     */
    protected function cifrarApiKeyEstiloDolibarr(string $apiKey): string
    {
        $clave = config('services.dolibarr.instance_key');
        $cifrado = 'AES-256-CTR';
        $iv = substr(md5('dolibarr'), 0, openssl_cipher_iv_length($cifrado));

        return 'dolcrypt:'.$cifrado.':'.$iv.':'.openssl_encrypt($apiKey, $cifrado, (string) $clave, 0, $iv);
    }
}
