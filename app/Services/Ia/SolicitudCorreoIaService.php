<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\DB;

/**
 * Redacta con IA el correo de una solicitud a partir de lo que pidió el cliente (productos y asunto), de un texto base o
 * plantilla opcional y de instrucciones del asesor. Nunca recibe el historial interno de precios ni los proveedores.
 */
class SolicitudCorreoIaService
{
    private const ETIQUETAS = '<p><br><strong><b><em><i><u><ul><ol><li><a><h3><h4>';

    public function __construct(private IaClient $ia, private IaConfigService $config, private SolicitudPromptsService $prompts)
    {
    }

    /**
     * @param  array<int, array{description: string, model?: ?string, qty?: mixed}>  $lineas
     * @return array{asunto: string, cuerpo: string}
     *
     * @throws IaException
     */
    public function redactar(object $usuario, object $sol, array $lineas, ?string $base, ?string $instrucciones, bool $firmaAutomatica = false): array
    {
        $p = DB::getTablePrefix();
        $contacto = DB::selectOne("SELECT TRIM(CONCAT_WS(' ', firstname, lastname)) n FROM {$p}socpeople WHERE rowid = ?", [$sol->contacto_id])->n ?? null;
        $cli = ! empty($sol->fk_soc) ? DB::selectOne("SELECT tva_intra nit, town FROM {$p}societe WHERE rowid = ?", [$sol->fk_soc]) : null;
        $asesor = DB::selectOne("SELECT TRIM(CONCAT_WS(' ', firstname, lastname)) n FROM {$p}user WHERE rowid = ?", [$usuario->getKey()])->n ?? null;

        $contexto = [
            'referencia' => $sol->ref,
            'cliente' => $sol->cliente,
            'cliente_nit' => $cli->nit ?? null,
            'cliente_ciudad' => $cli->town ?? null,
            'contacto' => $contacto,
            'asesor' => $asesor,
            'asunto_de_la_solicitud' => trim(strip_tags((string) $sol->asunto)),
            'productos' => array_map(fn ($l) => ['descripcion' => mb_substr((string) $l['description'], 0, 300), 'modelo' => $l['model'] ?? null, 'cantidad' => $l['qty'] ?? null], array_slice($lineas, 0, 40)),
            'texto_base' => $base !== null && trim($base) !== '' ? mb_substr(trim(strip_tags($base)), 0, 4000) : null,
            'importante' => $firmaAutomatica ? 'La firma del asesor se agrega sola al final del correo: no escribas firma, cargo ni datos de contacto; cierra solo con una despedida breve.' : null,
            'instrucciones_del_asesor' => $instrucciones !== null && trim($instrucciones) !== '' ? mb_substr(trim($instrucciones), 0, 1000) : null,
        ];

        $texto = $this->ia->chat($this->config->predeterminado(), $this->prompts->sistema('correo'), [['role' => 'user', 'content' => json_encode($contexto, JSON_UNESCAPED_UNICODE)]], false);
        $ini = strpos($texto, '{');
        $fin = strrpos($texto, '}');
        $json = $ini !== false && $fin !== false ? json_decode(substr($texto, $ini, $fin - $ini + 1), true) : null;
        if (! is_array($json) || ! is_string($json['cuerpo'] ?? null) || trim($json['cuerpo']) === '') {
            throw new IaException('La IA no devolvió un correo válido. Inténtalo de nuevo.');
        }

        return [
            'asunto' => mb_substr(trim(strip_tags((string) ($json['asunto'] ?? ''))), 0, 250),
            'cuerpo' => strip_tags($json['cuerpo'], self::ETIQUETAS),
        ];
    }
}
