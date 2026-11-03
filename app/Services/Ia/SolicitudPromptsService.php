<?php

namespace App\Services\Ia;

/**
 * Instrucciones (prompts) que un administrador puede editar para los dos usos de IA de la ficha de solicitudes:
 * el análisis de productos y la redacción del correo. Se guarda solo el texto de comportamiento; el formato de respuesta
 * (JSON) lo añade el código, para que editar el texto no pueda romper la lectura de la respuesta.
 */
class SolicitudPromptsService
{
    public const CONST_PROMPTS = 'PANEL_IA_PROMPTS_SOLICITUD';

    public const CLAVES = ['productos' => 'Búsqueda y análisis de productos', 'correo' => 'Redacción del correo al cliente'];

    private const DEFECTO = [
        'productos' => 'Eres un analista de compras de una empresa industrial colombiana que importa y suministra equipos. Recibes los productos de una solicitud '
            .'de un cliente, con el historial interno (cotizaciones y órdenes de compra, con sus precios en dólares y pesos). Para cada producto responde en español, muy breve: '
            .'un resumen (máximo 2 frases: qué es y cómo conviene abastecerlo según el historial; si el historial está vacío, dilo) y hasta 4 opciones '
            .'de compra de CUALQUIER PARTE DEL MUNDO (fabricante, distribuidores, marketplaces industriales), cada una con proveedor, país, precio, url y nota. '
            .'Usa la búsqueda web para encontrar precios reales; el precio va como texto con moneda y unidad (ej. "USD 245 c/u") y, si no lo encuentras, "No verificado". '
            .'Nunca inventes precios ni urls: pon la url solo si la viste en la búsqueda, si no déjala vacía. No inventes datos del historial interno.',
        'correo' => 'Eres el asistente comercial de una empresa industrial colombiana que suministra equipos e insumos. Redactas correos a clientes en español, con tono '
            .'profesional, cordial y breve. Confirma que recibimos su solicitud, resume lo que pidió (los productos con sus cantidades), indica que prepararemos la cotización y '
            .'los próximos pasos. No prometas precios, plazos ni disponibilidad que no se hayan dado, y no menciones proveedores, costos ni datos internos. '
            .'Si recibes un texto base o una plantilla, conserva su estructura y adáptala a esta solicitud. Si recibes instrucciones del asesor, síguelas. '
            .'Saluda al contacto por su nombre y firma con el nombre del asesor.',
    ];

    /** Formato de respuesta que el código exige; no es editable. */
    public const FORMATO = [
        'productos' => 'Responde SOLO con JSON: {"productos":[{"key":"<key recibida>","resumen":"...","donde_conseguir":[{"proveedor":"...","pais":"...","precio":"...","url":"","nota":"..."}]}]}.',
        'correo' => 'Responde SOLO con JSON: {"asunto":"...","cuerpo":"<p>...</p>"}. El cuerpo es HTML sencillo (p, br, strong, ul, li); sin html ni body, sin imágenes ni estilos, y termina con la firma.',
    ];

    public function texto(string $clave): string
    {
        $g = IaConfigService::json(self::CONST_PROMPTS) ?? [];
        $propio = isset($g[$clave]) && is_string($g[$clave]) ? trim($g[$clave]) : '';

        return $propio !== '' ? $propio : self::DEFECTO[$clave];
    }

    /** Texto completo enviado a la IA: el editable más el formato fijo. */
    public function sistema(string $clave): string
    {
        return $this->texto($clave)."\n\n".self::FORMATO[$clave];
    }

    /** @return array<string, array{nombre: string, texto: string, defecto: string, personalizado: bool}> */
    public function todos(): array
    {
        $out = [];
        foreach (self::CLAVES as $k => $nombre) {
            $texto = $this->texto($k);
            $out[$k] = ['nombre' => $nombre, 'texto' => $texto, 'defecto' => self::DEFECTO[$k], 'personalizado' => $texto !== self::DEFECTO[$k], 'formato' => self::FORMATO[$k]];
        }

        return $out;
    }

    /** @param  array<string, ?string>  $textos  clave => texto (vacío = volver al por defecto) */
    public function guardar(array $textos): void
    {
        $g = IaConfigService::json(self::CONST_PROMPTS) ?? [];
        foreach (array_keys(self::CLAVES) as $k) {
            if (! array_key_exists($k, $textos)) {
                continue;
            }
            $t = trim((string) $textos[$k]);
            if ($t === '' || $t === self::DEFECTO[$k]) {
                unset($g[$k]);
            } else {
                $g[$k] = mb_substr($t, 0, 6000);
            }
        }
        $g ? IaConfigService::escribir(self::CONST_PROMPTS, $g, 'Instrucciones editables de la IA de solicitudes (análisis de productos y correo)') : IaConfigService::borrar(self::CONST_PROMPTS);
    }
}
