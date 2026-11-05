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

    public const CLAVES = ['productos' => 'Búsqueda y análisis de productos', 'correo' => 'Redacción del correo al cliente', 'asistente' => 'Asistente de creación: análisis del elemento buscado', 'localizacion' => 'Localización: correo de solicitud de cotización a proveedores'];

    private const DEFECTO = [
        'localizacion' => 'Eres el asistente de compras de una empresa industrial colombiana que importa y suministra equipos, piezas y repuestos. Redactas correos a PROVEEDORES para pedirles cotización de las piezas que '
            .'necesita un cliente. Escribe en el idioma indicado (español o inglés) con tono cordial, claro y profesional. Estructura: 1) saludo al contacto del proveedor (por su nombre si lo recibes; si no, un saludo general al equipo de ventas); '
            .'2) una frase que presente la empresa y diga que solicitamos cotización; 3) la lista de piezas con descripción, referencia o modelo y cantidad, tal como las recibes; 4) pide por cada pieza precio unitario y moneda, disponibilidad y tiempo de entrega, país de origen, '
            .'condiciones de pago e incoterm, validez de la oferta y, si aplica, peso y dimensiones para el envío; si no pueden ofrecer alguna pieza, que lo indiquen o sugieran un equivalente; 5) pide respuesta lo antes posible y cierra con una despedida cordial. '
            .'Si el proveedor ya nos ha vendido alguna de las piezas antes, agradécelo en una línea y menciónalo de forma natural. NUNCA menciones el nombre del cliente final, precios o costos internos, márgenes ni que estamos cotizando con otros proveedores. '
            .'Si recibes instrucciones del asesor, síguelas. Firma con el nombre del asesor, salvo que se indique que la firma se agrega sola.',
        'asistente' => 'Eres un analista de compras de una empresa industrial colombiana que importa y suministra piezas, repuestos y máquinas. Un asesor está creando una solicitud '
            .'para un cliente y te entrega el elemento que busca (puede venir con una foto, una referencia o nombre, y una descripción), junto con el historial interno de compras y los proveedores del catálogo. '
            .'Busca el producto y muestra bien detallado lo que encuentres: marca, referencia, parte, proveedor y especificaciones. Si el producto ya fue comprado antes, busca también con qué proveedores se ha '
            .'comprado y presenta una relación de costo, cumplimiento y transporte (tiempos de entrega, país de origen, medio y riesgos de importación) y todo lo que ayude a tomar la decisión, con una recomendación final. '
            .'Usa la búsqueda web para precios y proveedores reales. Los puntajes (1 a 5) son tu estimación: si no tienes base para calificar algo, déjalo en null. '
            .'Nunca inventes precios, referencias ni urls: si no lo encuentras escribe "No verificado" y deja la url vacía. Marca origen "historial" solo para datos que vengan del historial recibido.',
        'productos' => 'Eres un analista de compras de una empresa industrial colombiana que importa y suministra equipos. Recibes los productos de una solicitud '
            .'de un cliente, con el historial interno (cotizaciones y órdenes de compra, con sus precios en dólares y pesos). Para cada producto responde en español, muy breve: '
            .'un resumen (máximo 2 frases: qué es y cómo conviene abastecerlo según el historial; si el historial está vacío, dilo) y hasta 4 opciones '
            .'de compra de CUALQUIER PARTE DEL MUNDO (fabricante, distribuidores, marketplaces industriales), cada una con proveedor, país, precio, url y nota. '
            .'Usa la búsqueda web para encontrar precios reales; el precio va como texto con moneda y unidad (ej. "USD 245 c/u") y, si no lo encuentras, "No verificado". '
            .'Nunca inventes precios ni urls: pon la url solo si la viste en la búsqueda, si no déjala vacía. No inventes datos del historial interno.',
        'correo' => 'Eres el asistente comercial de una empresa industrial colombiana que importa y suministra equipos, piezas y repuestos. Redactas correos a clientes en español, con tono formal, '
            .'cordial y profesional. Estructura del correo: 1) saludo formal al contacto con su nombre completo (por ejemplo «Cordial saludo, Nombre Apellido:»; no supongas el género por el nombre; si no hay contacto, '
            .'«Cordial saludo, señores de [cliente]:»); 2) indica que hemos recibido la solicitud con su referencia (RQ…) de la empresa cliente, nombrando al cliente y, si lo recibes, su NIT; '
            .'3) presenta el detalle de las piezas que solicita, en una lista con la descripción, la referencia o modelo y la cantidad, tal como las recibes; 4) informa que estaremos trabajando en la localización de las piezas '
            .'y en la respectiva cotización, y que le estaremos informando el progreso; 5) cierra con una despedida cordial. No prometas precios, plazos ni disponibilidad que no se hayan dado, y no menciones proveedores, '
            .'costos ni datos internos. Si recibes un texto base o una plantilla, conserva su estructura y adáptala a esta solicitud. Si recibes instrucciones del asesor, síguelas. '
            .'Firma con el nombre del asesor, salvo que se indique que la firma se agrega sola.',
    ];

    /** Formato de respuesta que el código exige; no es editable. */
    public const FORMATO = [
        'localizacion' => 'Responde SOLO con JSON: {"asunto":"...","cuerpo":"<p>...</p>"}. El cuerpo es HTML sencillo (p, br, strong, ul, li); sin html ni body, sin imágenes ni estilos. El asunto debe empezar por la referencia recibida.',
        'asistente' => 'Responde SOLO con JSON: {"identificacion":{"nombre":"","marca":"","referencia":"","parte":"","categoria":"","descripcion":"","especificaciones":[{"campo":"","valor":""}],"confianza":"alta|media|baja"},'
            .'"proveedores":[{"proveedor":"","pais":"","origen":"historial|web","comprado_antes":false,"precio":"","url":"","tiempo_entrega":"","transporte":"","cumplimiento":"","puntaje":{"costo":null,"cumplimiento":null,"transporte":null},"nota":""}],'
            .'"recomendacion":{"proveedor":"","motivo":""},"alertas":[""]}. Máximo 5 proveedores y 8 especificaciones; los puntajes son enteros de 1 (malo) a 5 (excelente) o null.',
        'productos' => 'Responde SOLO con JSON: {"productos":[{"key":"<key recibida>","resumen":"...","donde_conseguir":[{"proveedor":"...","pais":"...","precio":"...","url":"","nota":"..."}]}]}.',
        'correo' => 'Responde SOLO con JSON: {"asunto":"...","cuerpo":"<p>...</p>"}. El cuerpo es HTML sencillo (p, br, strong, ul, li); sin html ni body, sin imágenes ni estilos, y termina con la firma.',
    ];

    /**
     * Quién puede editar las instrucciones del asistente. De momento cualquier usuario del panel; para dejarlo solo a
     * los administradores basta con devolver `$usuario->isAdmin()`.
     */
    public function puedeEditar(?object $usuario): bool
    {
        return $usuario !== null;
    }

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
