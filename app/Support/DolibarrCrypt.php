<?php

namespace App\Support;

class DolibarrCrypt
{
    /**
     * Descifra un valor cifrado por Dolibarr con el formato dolcrypt.
     *
     * Formato: dolcrypt:<ciphering>:<iv_hex>:<ciphertext_base64>
     * Réplica de dolDecrypt() de Dolibarr (core/lib/security2.lib.php).
     *
     * La clave es $dolibarr_main_instance_unique_id de conf.php de Dolibarr,
     * configurada aquí vía env DOLIBARR_INSTANCE_KEY.
     */
    public static function decrypt(?string $chain, ?string $key = null): ?string
    {
        if ($chain === null || $chain === '') {
            return $chain;
        }

        $key = $key ?? (string) config('services.dolibarr.instance_key', env('DOLIBARR_INSTANCE_KEY', ''));

        if ($key === '' || ! preg_match('/^dolcrypt:([^:]+):(.+)$/', $chain, $reg)) {
            // Sin clave o no está cifrado: devolver tal cual.
            return $chain;
        }

        if (! function_exists('openssl_decrypt')) {
            return $chain;
        }

        $ciphering = $reg[1];
        $parts = explode(':', $reg[2]);

        // Dolibarr pasa el IV como string literal (los caracteres hex, sin decodificar),
        // tal cual lo hace dolDecrypt() en core/lib/security.lib.php.
        if (isset($parts[1]) && $parts[1] !== '') {
            $iv = $parts[0];
            $ciphertext = $parts[1];
            $plain = @openssl_decrypt($ciphertext, $ciphering, $key, 0, $iv);
        } else {
            $plain = @openssl_decrypt((string) $parts[0], $ciphering, $key, 0, '');
        }

        return $plain === false ? null : $plain;
    }
}
