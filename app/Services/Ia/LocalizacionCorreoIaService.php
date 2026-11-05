<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\DB;

/**
 * Redacta con IA el correo de solicitud de cotización a UN proveedor: recibe las piezas elegidas, el idioma, lo que ya se le
 * ha comprado a ese proveedor (para agradecerlo) y las instrucciones del asesor. Nunca recibe el cliente final, precios ni costos.
 */
class LocalizacionCorreoIaService
{
    private const ETIQUETAS = '<p><br><strong><b><em><i><u><ul><ol><li><a><h3><h4>';

    public function __construct(private IaClient $ia, private IaConfigService $config, private SolicitudPromptsService $prompts)
    {
    }

    /**
     * @param  array<string, mixed>  $proveedor  nombre, contacto?, pais?, comprado? (lista de productos ya comprados)
     * @param  array<int, array{descripcion: string, modelo?: ?string, cantidad?: mixed, ref?: ?string}>  $piezas
     * @return array{asunto: string, cuerpo: string}
     *
     * @throws IaException
     */
    public function redactar(object $usuario, string $refSolicitud, array $proveedor, array $piezas, string $idioma, ?string $instrucciones, bool $firmaAutomatica = true): array
    {
        $asesor = DB::selectOne('SELECT TRIM(CONCAT_WS(\' \', firstname, lastname)) n FROM '.DB::getTablePrefix().'user WHERE rowid = ?', [$usuario->getKey()])->n ?? null;
        $contexto = [
            'idioma' => $idioma === 'en' ? 'inglés' : 'español',
            'referencia_para_el_asunto' => $refSolicitud,
            'proveedor' => $proveedor['nombre'] ?? null,
            'contacto' => ! empty($proveedor['contacto']) ? $proveedor['contacto'] : null,
            'pais_del_proveedor' => $proveedor['pais'] ?? null,
            'asesor' => $asesor,
            'piezas' => array_map(fn ($l) => ['descripcion' => mb_substr((string) ($l['descripcion'] ?? ''), 0, 300), 'referencia' => $l['ref'] ?? null, 'modelo' => $l['modelo'] ?? null, 'cantidad' => $l['cantidad'] ?? null], array_slice($piezas, 0, 40)),
            'piezas_que_ya_nos_vendio' => ! empty($proveedor['comprado']) ? array_slice(array_map(fn ($c) => mb_substr((string) $c, 0, 120), (array) $proveedor['comprado']), 0, 20) : null,
            'importante' => $firmaAutomatica ? 'La firma del asesor se agrega sola al final: no escribas firma, cargo ni datos de contacto; cierra solo con una despedida breve.' : null,
            'instrucciones_del_asesor' => $instrucciones !== null && trim($instrucciones) !== '' ? mb_substr(trim($instrucciones), 0, 1000) : null,
        ];

        $texto = $this->ia->chat($this->config->predeterminado(), $this->prompts->sistema('localizacion'), [['role' => 'user', 'content' => json_encode($contexto, JSON_UNESCAPED_UNICODE)]], false);
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
