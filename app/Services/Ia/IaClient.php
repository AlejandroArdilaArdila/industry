<?php

namespace App\Services\Ia;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo de los tres proveedores (Claude, OpenAI y Gemini) para conversaciones de texto.
 * Devuelve siempre el texto de la respuesta; el llamador decide cómo interpretarlo.
 */
class IaClient
{
    public function __construct(private IaConfigService $config)
    {
    }

    /**
     * @param  array<int, array{role: 'user'|'assistant', content: string}>  $mensajes  empieza y termina en 'user'
     * @param  bool  $web  permitir búsqueda en internet (solo Claude y Gemini; en OpenAI se ignora)
     * @param  array<int, array{mime: string, data: string, nombre?: string}>  $adjuntos  archivos (PDF o imagen, `data` en base64) que se adjuntan al último mensaje del usuario
     *
     * @throws IaException
     */
    public function chat(string $proveedor, string $system, array $mensajes, bool $web = false, bool $json = true, array $adjuntos = []): string
    {
        $token = $this->config->token($proveedor);
        if (! $token) {
            throw new IaException('Falta el token de '.IaConfigService::PROVEEDORES[$proveedor]['nombre'].'. Un administrador debe configurarlo en Configuración → Parámetros IA.');
        }
        $modelo = $this->config->modelo($proveedor);
        $web = $web && IaConfigService::PROVEEDORES[$proveedor]['web'];

        return match ($proveedor) {
            'anthropic' => $this->anthropic($token, $modelo, $system, $this->conAdjuntos($mensajes, $adjuntos, 'anthropic'), $web),
            'openai' => $this->openai($token, $modelo, $system, $this->conAdjuntos($mensajes, $adjuntos, 'openai'), $json),
            'gemini' => $this->gemini($token, $modelo, $system, $this->conAdjuntos($mensajes, $adjuntos, 'gemini'), $web, $json),
        };
    }

    /**
     * Convierte el último mensaje del usuario en un mensaje con archivos, con el formato de cada proveedor.
     * `content` pasa de string a una lista de partes (los métodos de cada proveedor ya la aceptan).
     */
    private function conAdjuntos(array $mensajes, array $adjuntos, string $proveedor): array
    {
        if (! $adjuntos) {
            return $mensajes;
        }
        $i = array_key_last($mensajes);
        $texto = $mensajes[$i]['content'];
        $partes = [];
        foreach ($adjuntos as $a) {
            $pdf = $a['mime'] === 'application/pdf';
            $partes[] = match ($proveedor) {
                'anthropic' => ['type' => $pdf ? 'document' : 'image', 'source' => ['type' => 'base64', 'media_type' => $a['mime'], 'data' => $a['data']]],
                'openai' => $pdf
                    ? ['type' => 'file', 'file' => ['filename' => $a['nombre'] ?? 'documento.pdf', 'file_data' => "data:application/pdf;base64,{$a['data']}"]]
                    : ['type' => 'image_url', 'image_url' => ['url' => "data:{$a['mime']};base64,{$a['data']}"]],
                'gemini' => ['inline_data' => ['mime_type' => $a['mime'], 'data' => $a['data']]],
            };
        }
        $partes[] = $proveedor === 'gemini' ? ['text' => $texto] : ['type' => 'text', 'text' => $texto];
        $mensajes[$i]['content'] = $partes;

        return $mensajes;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(90)->acceptJson()->asJson();
    }

    private function fallo($res, string $proveedor): never
    {
        $j = $res->json();
        $msg = $j['error']['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : null) ?? $res->body();
        $hint = in_array($res->status(), [401, 403], true) ? ' (revisa el token)' : '';
        throw new IaException("{$proveedor} respondió {$res->status()}{$hint}: ".mb_substr((string) $msg, 0, 300));
    }

    private function anthropic(string $token, string $modelo, string $system, array $mensajes, bool $web): string
    {
        $body = ['model' => $modelo, 'max_tokens' => 2000, 'system' => $system, 'messages' => $mensajes];
        if ($web) {
            $body['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]];
        }
        $res = $this->http()->withHeaders(['x-api-key' => $token, 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', $body);
        if ($res->failed()) {
            $this->fallo($res, 'Claude');
        }
        // La respuesta mezcla bloques de búsqueda y de texto: el JSON final está en el último bloque de texto.
        $textos = collect($res->json('content', []))->where('type', 'text')->pluck('text');

        return trim((string) $textos->last());
    }

    private function openai(string $token, string $modelo, string $system, array $mensajes, bool $json): string
    {
        $body = ['model' => $modelo, 'messages' => array_merge([['role' => 'system', 'content' => $system]], $mensajes)];
        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        $res = $this->http()->withToken($token)->post('https://api.openai.com/v1/chat/completions', $body);
        if ($res->failed()) {
            $this->fallo($res, 'OpenAI');
        }

        return trim((string) $res->json('choices.0.message.content'));
    }

    private function gemini(string $token, string $modelo, string $system, array $mensajes, bool $web, bool $json): string
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => is_array($m['content']) ? $m['content'] : [['text' => $m['content']]]], $mensajes),
        ];
        if ($web) {
            $body['tools'] = [['google_search' => (object) []]]; // Gemini no admite JSON forzado junto con búsqueda
        } elseif ($json) {
            $body['generationConfig'] = ['responseMimeType' => 'application/json'];
        }
        $res = $this->http()->withHeaders(['x-goog-api-key' => $token])->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent", $body);
        if ($res->failed()) {
            $this->fallo($res, 'Gemini');
        }
        $partes = collect($res->json('candidates.0.content.parts', []))->pluck('text')->filter();

        return trim($partes->implode(''));
    }
}
