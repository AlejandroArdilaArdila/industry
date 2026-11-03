<?php

namespace App\Services\Ia;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros de IA del panel (proveedor, tokens y prompts por módulo). Se guardan en la tabla `const`
 * de Dolibarr (entity 1) como JSON, igual que el resto de ajustes del panel. Los tokens van cifrados con
 * APP_KEY y nunca se devuelven en claro a la interfaz.
 */
class IaConfigService
{
    public const CONST_PROVEEDORES = 'PANEL_IA_PROVEEDORES';

    public const PROVEEDORES = [
        'anthropic' => ['nombre' => 'Claude (Anthropic)', 'modelo' => 'claude-sonnet-5-5', 'web' => true],
        'openai' => ['nombre' => 'OpenAI (ChatGPT)', 'modelo' => 'gpt-4.1', 'web' => false],
        'gemini' => ['nombre' => 'Google Gemini', 'modelo' => 'gemini-2.5-flash', 'web' => true],
    ];

    /** Configuración completa sin exponer los tokens (solo si hay uno y sus últimos 4 caracteres). */
    public function publica(): array
    {
        $c = $this->leer();
        $out = ['predeterminado' => $c['predeterminado'], 'proveedores' => []];
        foreach (self::PROVEEDORES as $id => $meta) {
            $p = $c['proveedores'][$id] ?? [];
            $token = $this->token($id);
            $out['proveedores'][$id] = [
                'nombre' => $meta['nombre'],
                'soportaWeb' => $meta['web'],
                'modelo' => $p['modelo'] ?? $meta['modelo'],
                'modeloDefecto' => $meta['modelo'],
                'tieneToken' => $token !== null,
                'tokenFinal' => $token !== null ? substr($token, -4) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array{predeterminado?: string, proveedores?: array<string, array{modelo?: string, token?: ?string, borrar_token?: bool}>}  $datos
     *                                                                                                                                  token vacío = conservar el actual
     */
    public function guardar(array $datos): void
    {
        $c = $this->leer();
        if (isset($datos['predeterminado']) && isset(self::PROVEEDORES[$datos['predeterminado']])) {
            $c['predeterminado'] = $datos['predeterminado'];
        }
        foreach ((array) ($datos['proveedores'] ?? []) as $id => $p) {
            if (! isset(self::PROVEEDORES[$id])) {
                continue;
            }
            $c['proveedores'][$id]['modelo'] = trim((string) ($p['modelo'] ?? '')) ?: self::PROVEEDORES[$id]['modelo'];
            if (! empty($p['borrar_token'])) {
                unset($c['proveedores'][$id]['token']);
            } elseif (filled($p['token'] ?? null)) {
                $c['proveedores'][$id]['token'] = Crypt::encryptString(trim($p['token']));
            }
        }
        self::escribir(self::CONST_PROVEEDORES, $c, 'Proveedores y tokens de IA del panel (cifrados)');
    }

    public function predeterminado(): string
    {
        return $this->leer()['predeterminado'];
    }

    public function modelo(string $proveedor): string
    {
        return $this->leer()['proveedores'][$proveedor]['modelo'] ?? self::PROVEEDORES[$proveedor]['modelo'];
    }

    public function token(string $proveedor): ?string
    {
        $enc = $this->leer()['proveedores'][$proveedor]['token'] ?? null;
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Lee un ajuste JSON de `const`. */
    public static function json(string $nombre): ?array
    {
        $v = Configuracion::valores([$nombre])[$nombre] ?? null;
        $d = is_string($v) ? json_decode($v, true) : null;

        return is_array($d) ? $d : null;
    }

    public static function escribir(string $nombre, array $valor, string $nota): void
    {
        $q = DB::table('const')->where('name', $nombre)->where('entity', 1);
        $json = json_encode($valor, JSON_UNESCAPED_UNICODE);
        if ($q->exists()) {
            $q->update(['value' => $json]);
        } else {
            DB::table('const')->insert(['name' => $nombre, 'entity' => 1, 'value' => $json, 'type' => 'chaine', 'visible' => 0, 'note' => $nota]);
        }
    }

    public static function borrar(string $nombre): void
    {
        DB::table('const')->where('name', $nombre)->where('entity', 1)->delete();
    }

    private function leer(): array
    {
        $c = self::json(self::CONST_PROVEEDORES) ?? [];

        return ['predeterminado' => isset(self::PROVEEDORES[$c['predeterminado'] ?? '']) ? $c['predeterminado'] : 'anthropic', 'proveedores' => (array) ($c['proveedores'] ?? [])];
    }
}
