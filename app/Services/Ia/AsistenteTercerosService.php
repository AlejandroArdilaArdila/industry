<?php

namespace App\Services\Ia;

use App\Models\DolibarrUser;
use App\Models\Sociedad;
use App\Services\Panel\FichaExtrafields;
use App\Services\Societe\TerceroFichaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asistente de IA para crear terceros conversando. El administrador parametriza qué datos de la ficha se piden, en qué
 * orden y con qué reglas (tipo de dato, único, longitud, patrón); este servicio arma el prompt con esa parametrización,
 * conversa con el proveedor elegido y valida SIEMPRE en el servidor lo que la IA extrae: primero las reglas del
 * administrador y después las reglas originales de la ficha, para que un tercero que llega a "listo" se pueda crear.
 *
 * Los datos obligatorios de la ficha original (documento, razón social, tipo de tercero…, y los extrafields marcados como
 * obligatorios en Dolibarr) están bloqueados: siempre se piden y sus reglas no se pueden relajar.
 */
class AsistenteTercerosService
{
    public const CONST_CONFIG = 'PANEL_IA_TERCEROS';

    public const TIPOS = [
        'texto' => 'Texto libre',
        'alfanumerico' => 'Alfanumérico (letras y números)',
        'numerico' => 'Numérico (solo dígitos)',
        'entero' => 'Entero',
        'decimal' => 'Decimal',
        'email' => 'Correo electrónico',
        'telefono' => 'Teléfono',
        'url' => 'URL',
        'fecha' => 'Fecha (AAAA-MM-DD)',
    ];

    /** Tipos que no admiten cambiar el tipo de dato: el valor sale de una lista. */
    private const TIPOS_LISTA = ['lista', 'multilista', 'booleano'];

    /**
     * Campos del formulario original. g = grupo visible, sec = paso de la ficha donde se guarda, t = tipo de dato,
     * m = longitud máxima de la base de datos, lock = obligatorio en la ficha (no editable), uf = único por regla
     * fija, up = se puede marcar como único, web = puede sugerirse buscando en internet.
     */
    private const BASE = [
        'tipo_tercero' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Tipo de tercero', 't' => 'lista', 'f' => 'cliente, proveedor o ambos', 'lock' => true],
        'tipo_documento' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Tipo de documento', 't' => 'lista', 'f' => 'NIT, CC, CE, TI, PP, PEP o NIE', 'lock' => true],
        'documento' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Número de documento', 't' => 'alfanumerico', 'm' => 128, 'f' => 'dígitos; para NIT se acepta el dígito de verificación tras un guion (900123456-7)', 'lock' => true, 'uf' => true],
        'nom' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Razón social / nombre', 't' => 'texto', 'm' => 128, 'f' => 'texto', 'lock' => true, 'up' => true, 'web' => true],
        'name_alias' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Nombre comercial (alias)', 't' => 'texto', 'm' => 128, 'f' => 'texto', 'up' => true, 'web' => true],
        'ape' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Actividad económica (CIIU)', 't' => 'numerico', 'm' => 4, 'f' => 'código CIIU de 4 dígitos', 'web' => true],
        'tipo_entidad' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Tipo de entidad', 't' => 'lista', 'f' => 'una opción del catálogo'],
        'forma_juridica' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Forma jurídica', 't' => 'lista', 'f' => 'una opción del catálogo'],
        'code_client' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Código de cliente', 't' => 'alfanumerico', 'm' => 24, 'f' => 'si se omite se genera solo', 'uf' => true],
        'code_fournisseur' => ['g' => 'Identificación', 'sec' => 'identificacion', 'e' => 'Código de proveedor', 't' => 'alfanumerico', 'm' => 24, 'f' => 'si se omite se genera solo', 'uf' => true],

        'pais' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'País', 't' => 'lista', 'f' => 'país (por defecto Colombia)'],
        'departamento' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'Departamento', 't' => 'lista', 'f' => 'departamento de Colombia', 'web' => true],
        'ciudad' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'Ciudad', 't' => 'lista', 'f' => 'municipio de Colombia', 'web' => true],
        'address' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'Dirección', 't' => 'texto', 'm' => 255, 'f' => 'texto', 'web' => true],
        'phone' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'Teléfono fijo', 't' => 'telefono', 'm' => 30, 'f' => 'dígitos', 'up' => true, 'web' => true],
        'phone_mobile' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'Celular', 't' => 'telefono', 'm' => 30, 'f' => 'dígitos (10 en Colombia)', 'up' => true],
        'fax' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'Fax', 't' => 'telefono', 'm' => 30, 'f' => 'dígitos'],
        'email' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'Correo electrónico', 't' => 'email', 'm' => 128, 'f' => 'correo válido', 'up' => true, 'web' => true],
        'url' => ['g' => 'Contacto', 'sec' => 'contacto', 'e' => 'Sitio web', 't' => 'url', 'm' => 255, 'f' => 'URL', 'up' => true, 'web' => true],

        'nivel_precio' => ['g' => 'Comercial', 'sec' => 'comercial', 'e' => 'Nivel de precio', 't' => 'lista', 'f' => 'una opción del catálogo'],
        'descuento' => ['g' => 'Comercial', 'sec' => 'comercial', 'e' => 'Descuento (%)', 't' => 'decimal', 'f' => 'número entre 0 y 100'],
        'medio_pago' => ['g' => 'Comercial', 'sec' => 'comercial', 'e' => 'Medio de pago', 't' => 'lista', 'f' => 'una opción del catálogo'],
        'condicion_pago' => ['g' => 'Comercial', 'sec' => 'comercial', 'e' => 'Condición de pago', 't' => 'lista', 'f' => 'una opción del catálogo'],
        'limite_credito' => ['g' => 'Comercial', 'sec' => 'comercial', 'e' => 'Límite de crédito', 't' => 'decimal', 'f' => 'número mayor o igual a 0'],

        'categorias_cliente' => ['g' => 'Categorías', 'sec' => 'categorias', 'e' => 'Categorías de cliente', 't' => 'multilista', 'f' => 'una o varias del catálogo, separadas por coma'],
        'categorias_proveedor' => ['g' => 'Categorías', 'sec' => 'categorias', 'e' => 'Categorías de proveedor', 't' => 'multilista', 'f' => 'una o varias del catálogo, separadas por coma'],

        'note_public' => ['g' => 'Notas', 'sec' => 'notas', 'e' => 'Nota pública', 't' => 'texto', 'f' => 'texto libre'],
        'note_private' => ['g' => 'Notas', 'sec' => 'notas', 'e' => 'Nota privada', 't' => 'texto', 'f' => 'texto libre'],
    ];

    /** Columna de societe de cada campo (para el valor único y para armar los datos de la ficha). */
    private const COL = [
        'documento' => 'siren', 'tipo_documento' => 'siret', 'nom' => 'nom', 'name_alias' => 'name_alias', 'ape' => 'ape', 'code_client' => 'code_client',
        'code_fournisseur' => 'code_fournisseur', 'address' => 'address', 'phone' => 'phone', 'phone_mobile' => 'phone_mobile', 'fax' => 'fax', 'email' => 'email',
        'url' => 'url', 'descuento' => 'remise_client', 'limite_credito' => 'outstanding_limit', 'note_public' => 'note_public', 'note_private' => 'note_private',
        'tipo_entidad' => 'fk_typent', 'forma_juridica' => 'fk_forme_juridique', 'pais' => 'fk_pays', 'nivel_precio' => 'price_level', 'medio_pago' => 'mode_reglement',
        'condicion_pago' => 'cond_reglement',
    ];

    private const ACTIVOS_DEFECTO = ['tipo_tercero', 'tipo_documento', 'documento', 'nom', 'email', 'phone_mobile', 'address', 'departamento', 'ciudad'];

    private const SECCIONES_ORDEN = ['identificacion', 'contacto', 'comercial', 'tributario', 'categorias', 'adicionales', 'notas'];

    private ?array $catalogoMemo = null;

    private ?array $listasMemo = null;

    public function __construct(
        private IaClient $ia,
        private IaConfigService $iaConfig,
        private TerceroFichaService $ficha,
        private FichaExtrafields $extrafields,
    ) {
    }

    // ------------------------------------------------------------ catálogo (campos del formulario original)

    /**
     * Todos los campos de la ficha: los fijos más los extrafields de Dolibarr visibles en el formulario.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalogo(): array
    {
        if ($this->catalogoMemo !== null) {
            return $this->catalogoMemo;
        }
        $cat = [];
        foreach (self::BASE as $k => $m) {
            $cat[$k] = $m + ['m' => null, 'lock' => false, 'uf' => false, 'up' => false, 'web' => false];
        }
        $porSeccion = ['comercial' => 'Comercial', 'tributario' => 'Tributario', 'adicionales' => 'Adicionales'];
        $extra = [];
        foreach ($this->extrafields->visibles('societe') as $def) {
            if ((int) $def->list === 5) {
                continue; // solo lectura en la ficha
            }
            $sec = match ($this->ficha->grupoDe($def->name)) { 'credito' => 'comercial', 'tributario' => 'tributario', default => 'adicionales' };
            $tipo = match ($def->type) {
                'boolean' => 'booleano', 'select', 'sellist' => 'lista', 'int' => 'entero', 'double', 'price', 'pricecy' => 'decimal',
                'date', 'datetime' => 'fecha', 'mail' => 'email', 'url' => 'url', 'phone' => 'telefono', default => 'texto',
            };
            $extra['ef.'.$def->name] = ['g' => $porSeccion[$sec], 'sec' => $sec, 'e' => $def->label, 't' => $tipo, 'm' => $tipo === 'texto' && (int) $def->size > 0 ? (int) $def->size : null,
                'f' => $def->help ?: ($tipo === 'lista' ? 'una opción del catálogo' : $tipo), 'lock' => (int) $def->fieldrequired === 1 && $tipo !== 'booleano', 'uf' => false, 'up' => ! in_array($tipo, self::TIPOS_LISTA, true), 'web' => false, 'ef' => $def->name, 'def' => $def];
        }

        // Orden del formulario original: los pasos de la ficha y, dentro de cada uno, sus campos.
        $todo = $cat + $extra;
        uasort($todo, fn ($a, $b) => array_search($a['sec'], self::SECCIONES_ORDEN) <=> array_search($b['sec'], self::SECCIONES_ORDEN));

        return $this->catalogoMemo = $todo;
    }

    /** Catálogo para la pantalla de configuración (sin objetos internos). */
    public function catalogoPublico(): array
    {
        $out = [];
        foreach ($this->catalogo() as $k => $m) {
            $out[$k] = ['etiqueta' => $m['e'], 'grupo' => $m['g'], 'tipo' => $m['t'], 'tipoFijo' => in_array($m['t'], self::TIPOS_LISTA, true) || $m['lock'],
                'max' => $m['m'], 'bloqueado' => $m['lock'], 'unicoFijo' => $m['uf'], 'unicoPosible' => $m['up'] || $m['uf'], 'formato' => $m['f'], 'web' => $m['web']];
        }

        return $out;
    }

    // ------------------------------------------------------------ configuración (prompt parametrizable)

    public function configDefecto(): array
    {
        $campos = [];
        foreach ($this->catalogo() as $clave => $m) {
            $campos[] = $this->campoDefecto($clave, $m, $m['lock'] || in_array($clave, self::ACTIVOS_DEFECTO, true));
        }

        return [
            'proveedor' => '',
            'rol' => 'Eres el asistente de creación de terceros (clientes y proveedores) de una empresa colombiana. Ayudas a un usuario del ERP a registrar un tercero de forma rápida y sin errores.',
            'tono' => 'Cercano, profesional y breve. Trata al usuario de "tú".',
            'saludo' => 'Hola, te ayudo a crear el tercero. Cuéntame: ¿es un cliente o un proveedor, y cuál es su NIT o documento?',
            'reglas_extra' => '',
            'un_dato_a_la_vez' => false,
            'aceptar_varios_datos' => true,
            'buscar_web' => false,
            'confirmar_antes_de_crear' => true,
            'mayusculas_nombre' => true,
            'detectar_duplicados' => true,
            'permitir_rut' => true,
            'campos' => $campos,
        ];
    }

    private function campoDefecto(string $clave, array $m, bool $activo): array
    {
        return ['clave' => $clave, 'activo' => $activo, 'obligatorio' => $m['lock'], 'etiqueta' => $m['e'], 'instrucciones' => '', 'validacion' => '', 'web' => false,
            'tipo' => $m['t'], 'unico' => $m['uf'], 'min' => null, 'max' => $m['m'], 'patron' => ''];
    }

    /** Aplica los bloqueos: un campo obligatorio de la ficha siempre se pide y conserva sus reglas de fábrica. */
    private function forzar(array $f): array
    {
        $m = $this->catalogo()[$f['clave']];
        if (in_array($m['t'], self::TIPOS_LISTA, true) || $m['lock']) {
            $f['tipo'] = $m['t'];
        }
        if ($m['uf']) {
            $f['unico'] = true;
        } elseif (! $m['up']) {
            $f['unico'] = false;
        }
        if ($m['lock']) {
            $f['activo'] = true;
            $f['obligatorio'] = true;
            $f['max'] = $m['m'];
            $f['min'] = null;
            $f['patron'] = '';
        } elseif ($m['m'] && (! $f['max'] || $f['max'] > $m['m'])) {
            $f['max'] = $m['m']; // nunca más largo que la columna
        }

        return $f;
    }

    public function config(): array
    {
        $def = $this->configDefecto();
        $g = IaConfigService::json(self::CONST_CONFIG) ?? [];
        $out = array_merge($def, array_intersect_key($g, $def));

        $guardados = [];
        foreach ((array) ($g['campos'] ?? []) as $c) {
            if (isset($c['clave']) && isset($this->catalogo()[$c['clave']])) {
                $guardados[$c['clave']] = $c;
            }
        }
        $defPorClave = array_column($def['campos'], null, 'clave');
        $campos = [];
        foreach ($guardados as $clave => $c) {
            $campos[] = $this->forzar(array_merge($defPorClave[$clave], array_intersect_key($c, $defPorClave[$clave])));
        }
        foreach ($defPorClave as $clave => $c) {
            if (! isset($guardados[$clave])) {
                $campos[] = $this->forzar($g ? array_merge($c, ['activo' => false]) : $c);
            }
        }
        $out['campos'] = $campos;
        $out['proveedor'] = isset(IaConfigService::PROVEEDORES[$out['proveedor']]) ? $out['proveedor'] : '';

        return $out;
    }

    public function guardarConfig(array $d): void
    {
        $def = $this->configDefecto();
        $c = [];
        foreach (['proveedor', 'rol', 'tono', 'saludo', 'reglas_extra'] as $k) {
            $c[$k] = trim((string) ($d[$k] ?? $def[$k]));
        }
        foreach (['un_dato_a_la_vez', 'aceptar_varios_datos', 'buscar_web', 'confirmar_antes_de_crear', 'mayusculas_nombre', 'detectar_duplicados', 'permitir_rut'] as $k) {
            $c[$k] = (bool) ($d[$k] ?? false);
        }
        $c['campos'] = [];
        foreach ((array) ($d['campos'] ?? []) as $f) {
            $clave = $f['clave'] ?? '';
            if (! isset($this->catalogo()[$clave])) {
                continue;
            }
            $tipo = array_key_exists($f['tipo'] ?? '', self::TIPOS) ? $f['tipo'] : $this->catalogo()[$clave]['t'];
            $int = fn ($v) => ($v === null || $v === '' || ! is_numeric($v)) ? null : max(0, (int) $v);
            $min = $int($f['min'] ?? null);
            $max = $int($f['max'] ?? null);
            if ($min !== null && $max !== null && $min > $max) {
                [$min, $max] = [$max, $min];
            }
            $c['campos'][] = $this->forzar([
                'clave' => $clave,
                'activo' => (bool) ($f['activo'] ?? false),
                'obligatorio' => (bool) ($f['obligatorio'] ?? false),
                'etiqueta' => trim((string) ($f['etiqueta'] ?? '')) ?: $this->catalogo()[$clave]['e'],
                'instrucciones' => trim((string) ($f['instrucciones'] ?? '')),
                'validacion' => trim((string) ($f['validacion'] ?? '')),
                'web' => (bool) ($f['web'] ?? false),
                'tipo' => $tipo,
                'unico' => (bool) ($f['unico'] ?? false),
                'min' => $min,
                'max' => $max,
                'patron' => trim((string) ($f['patron'] ?? '')),
            ]);
        }
        IaConfigService::escribir(self::CONST_CONFIG, $c, 'Parametrización del asistente IA de creación de terceros');
    }

    /** True si la expresión regular es válida (se comprueba al guardar para no romper la validación después). */
    public static function patronValido(string $patron): bool
    {
        return $patron === '' || @preg_match(self::regex($patron), '') !== false;
    }

    private static function regex(string $patron): string
    {
        return '/'.str_replace('/', '\/', $patron).'/u';
    }

    public function restablecerConfig(): void
    {
        IaConfigService::borrar(self::CONST_CONFIG);
    }

    // ------------------------------------------------------------ conversación

    /** Mensaje inicial (sin gastar una llamada a la IA) y metadatos para pintar la interfaz. */
    public function inicio(): array
    {
        $c = $this->config();

        return ['saludo' => $c['saludo'], 'campos' => $this->camposActivos($c), 'confirmar' => $c['confirmar_antes_de_crear'], 'permitirRut' => $c['permitir_rut']];
    }

    /**
     * Un turno de conversación.
     *
     * @param  array<int, array{role: string, content: string}>  $historial  termina con el mensaje del usuario
     * @param  array<string, mixed>  $datos  lo recopilado hasta ahora
     */
    public function turno(array $historial, array $datos): array
    {
        $c = $this->config();
        $proveedor = $c['proveedor'] ?: $this->iaConfig->predeterminado();
        $system = $this->prompt($c);
        $mensajes = collect($historial)
            ->map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => mb_substr((string) $m['content'], 0, 4000)])
            ->take(-30)->values()->all();
        // Anthropic y Gemini exigen que la conversación empiece con el usuario.
        while ($mensajes && $mensajes[0]['role'] !== 'user') {
            array_shift($mensajes);
        }
        if (! $mensajes) {
            throw new IaException('Escribe o dicta un mensaje para empezar.');
        }

        $estado = $this->procesar($c, $datos);
        $respuesta = ['mensaje' => '', 'listo' => false];

        for ($intento = 0; $intento < 2; $intento++) {
            $envio = $mensajes;
            $ultimo = array_pop($envio);
            $ultimo['content'] .= "\n\n".$this->bloqueEstado($estado);
            $envio[] = $ultimo;

            $texto = $this->ia->chat($proveedor, $system, $envio, $c['buscar_web']);
            $r = $this->interpretar($texto);
            $estado = $this->procesar($c, array_merge($estado['datos'], array_filter($r['datos'], fn ($v) => $v !== null && $v !== '')));
            $respuesta = ['mensaje' => $r['mensaje'], 'listo' => $r['listo']];

            // Si la IA da por terminado pero el servidor encuentra errores, se le devuelve el control una vez.
            if (! ($respuesta['listo'] && $estado['errores']) || $intento === 1) {
                break;
            }
            $mensajes[] = ['role' => 'assistant', 'content' => $texto];
            $mensajes[] = ['role' => 'user', 'content' => '[SISTEMA] La validación del servidor encontró problemas; explícaselos al usuario y pide solo lo necesario para corregirlos.'];
        }

        $listo = $respuesta['listo'] && ! $estado['errores'] && ! $estado['faltan'];

        return [
            'mensaje' => $respuesta['mensaje'] ?: ($listo ? 'Tengo todos los datos. Revisa el resumen y confirma.' : '¿Me confirmas el siguiente dato?'),
            'datos' => $estado['datos'],
            'listo' => $listo,
            'errores' => $estado['errores'],
            'avisos' => $estado['avisos'],
            'faltan' => $estado['faltan'],
            'campos' => $this->camposActivos($c),
        ];
    }

    /** Valida lo recopilado y crea el tercero. Lanza ValidationException con los mensajes por campo. */
    public function crear(array $datos, DolibarrUser $usuario): Sociedad
    {
        $estado = $this->procesar($this->config(), $datos);
        if ($estado['faltan'] || $estado['errores']) {
            throw ValidationException::withMessages($estado['errores'] ?: ['datos' => 'Faltan datos obligatorios: '.implode(', ', $estado['faltan']).'.']);
        }

        $cols = $estado['cols'];
        $tercero = $this->ficha->crear($cols, [], $usuario);
        $catalogo = $this->catalogo();
        // Cada paso de la ficha se guarda con sus propios datos, igual que el formulario original.
        foreach (array_slice(self::SECCIONES_ORDEN, 1) as $sec) {
            $hay = collect($estado['datos'])->keys()->contains(fn ($k) => ($catalogo[$k]['sec'] ?? null) === $sec);
            if ($hay) {
                $this->ficha->guardar($tercero, $sec, $cols + $estado['cats'], $estado['ef'], $usuario);
            }
        }

        return $tercero;
    }


    // ------------------------------------------------------------ RUT (DIAN)

    private const PROMPT_RUT = <<<'TXT'
Eres un extractor de datos del RUT (Registro Único Tributario) de la DIAN de Colombia. Lee el documento adjunto y responde SOLO con un objeto JSON, sin texto adicional ni bloques de código, con esta forma exacta (usa null cuando un dato no aparezca o no se lea con certeza; NUNCA inventes):
{"es_rut": true, "legible": true, "estado": "ACTIVO", "fecha_documento": "AAAA-MM-DD", "tipo_contribuyente": "Persona jurídica", "nit": "solo dígitos sin dígito de verificación", "dv": "un dígito", "razon_social": null, "primer_apellido": null, "segundo_apellido": null, "primer_nombre": null, "otros_nombres": null, "nombre_comercial": null, "sigla": null, "pais": null, "departamento": null, "ciudad": null, "direccion": null, "correo": null, "telefono1": null, "telefono2": null, "actividad_principal": "código CIIU de 4 dígitos", "responsabilidades": ["códigos de las responsabilidades, ej. 05, 48"]}
"es_rut" es false si el documento no es un RUT; "legible" es false si la imagen está borrosa o incompleta. "estado" es el estado del registro (ACTIVO, SUSPENDIDO, CANCELADO…) si aparece. Para personas naturales llena los apellidos y nombres y deja razon_social en null. Copia el NIT dígito por dígito con mucho cuidado.
TXT;

    /**
     * Lee un RUT adjunto, mapea sus datos a los campos activos del asistente (el documento se envía con su dígito de
     * verificación para que el servidor lo compruebe) y continúa la conversación con lo extraído como contexto.
     *
     * @param  array{mime: string, data: string, nombre: string}  $archivo
     */
    public function desdeRut(array $archivo, array $historial, array $datos): array
    {
        $c = $this->config();
        if (! $c['permitir_rut']) {
            throw new IaException('La lectura del RUT está desactivada en la configuración del asistente.');
        }
        $proveedor = $c['proveedor'] ?: $this->iaConfig->predeterminado();
        $texto = $this->ia->chat($proveedor, self::PROMPT_RUT, [['role' => 'user', 'content' => 'Extrae los datos del RUT adjunto.']], false, true, [$archivo]);
        $r = json_decode(substr($texto, (int) strpos($texto, '{'), (int) strrpos($texto, '}') - (int) strpos($texto, '{') + 1), true);
        if (! is_array($r)) {
            throw new IaException('No pude interpretar el documento. Intenta con un PDF original del RUT o una imagen más nítida.');
        }
        if (($r['es_rut'] ?? true) === false) {
            throw new IaException('El archivo no parece un RUT de la DIAN.');
        }
        if (($r['legible'] ?? true) === false || empty($r['nit'])) {
            throw new IaException('No logro leer el NIT del documento. Sube un PDF original del RUT o una imagen más nítida.');
        }

        [$extraido, $avisos] = $this->mapearRut($r, $c, $datos);
        $datos = array_merge($datos, $extraido);

        // La conversación sigue con un mensaje del usuario que describe lo leído: así la IA lo resume y pide solo lo que falte.
        $resumen = collect($extraido)->map(fn ($v, $k) => ($this->catalogo()[$k]['e'] ?? $k).': '.$v)->implode('; ');
        $nota = "Adjunté el RUT ({$archivo['nombre']}). Datos leídos del RUT: {$resumen}.".($avisos ? ' Avisos: '.implode(' ', $avisos) : '')
            .' Resume brevemente lo que leíste, avísame de cualquier problema y pídeme solo los datos que aún falten.';
        $historial[count($historial) - 1]['content'] = $nota;

        $res = $this->turno($historial, $datos);
        $res['avisos'] = array_values(array_unique(array_merge($avisos, $res['avisos'])));
        $res['rut'] = ['archivo' => $archivo['nombre'], 'campos' => array_keys($extraido), 'estado' => $r['estado'] ?? null];

        return $res;
    }

    /** @return array{0: array<string, string>, 1: array<int, string>} datos por clave del asistente y avisos */
    private function mapearRut(array $r, array $c, array $datos): array
    {
        $activos = array_column(array_filter($c['campos'], fn ($f) => $f['activo']), null, 'clave');
        $limpio = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $out = [];
        $avisos = [];

        $nit = preg_replace('/\D/', '', (string) ($r['nit'] ?? ''));
        $dv = preg_replace('/\D/', '', (string) ($r['dv'] ?? ''));
        $doc = $nit.($dv !== '' ? "-{$dv}" : '');
        if (! empty($datos['documento']) && preg_replace('/-\d$/', '', preg_replace('/[\s.]/', '', (string) $datos['documento'])) !== $nit) {
            $avisos[] = "El NIT del RUT ({$nit}) no coincide con el documento que ya se había dado ({$datos['documento']}): pregunta cuál es el correcto.";
        } else {
            $out['tipo_documento'] = 'NIT';
            $out['documento'] = $doc; // con DV: procesar() verifica el dígito
        }

        $natural = ! $limpio($r['razon_social'] ?? null);
        $nombre = $natural
            ? implode(' ', array_filter(array_map($limpio, [$r['primer_nombre'] ?? null, $r['otros_nombres'] ?? null, $r['primer_apellido'] ?? null, $r['segundo_apellido'] ?? null])))
            : $limpio($r['razon_social']);
        $tel = array_values(array_filter(array_map(fn ($t) => preg_replace('/[^\d+]/', '', (string) $t), [$r['telefono1'] ?? null, $r['telefono2'] ?? null])));
        $movil = collect($tel)->first(fn ($t) => preg_match('/^(\+?57)?3\d{9}$/', $t));
        $fijo = collect($tel)->first(fn ($t) => $t !== $movil);

        $mapa = [
            'nom' => $nombre ?: null,
            'name_alias' => $limpio($r['nombre_comercial'] ?? null),
            'address' => $limpio($r['direccion'] ?? null),
            'departamento' => $limpio($r['departamento'] ?? null),
            'ciudad' => $limpio($r['ciudad'] ?? null),
            'email' => $limpio($r['correo'] ?? null),
            'phone_mobile' => $movil,
            'phone' => $fijo,
            'ape' => preg_match('/^\d{4}$/', (string) ($r['actividad_principal'] ?? '')) ? $r['actividad_principal'] : null,
        ];
        foreach ($mapa as $k => $v) {
            if ($v !== null && (isset($activos[$k]) || in_array($k, ['tipo_documento', 'documento'], true))) {
                $out[$k] = $v;
            }
        }

        $estado = mb_strtoupper((string) ($r['estado'] ?? ''));
        if ($estado !== '' && $estado !== 'ACTIVO' && $estado !== 'REGISTRO ACTIVO') {
            $avisos[] = "El RUT indica estado {$estado}: confirma con el usuario si se puede crear el tercero.";
        }
        if (! empty($r['fecha_documento']) && ($t = strtotime((string) $r['fecha_documento'])) && $t < strtotime('-1 year')) {
            $avisos[] = 'El RUT tiene más de un año de antigüedad ('.date('Y-m-d', $t).'): conviene pedir uno reciente.';
        }
        $faltan = array_diff(array_keys($mapa), array_keys($out));
        if ($faltan && ! $natural && ! $limpio($r['razon_social'] ?? null)) {
            $avisos[] = 'No pude leer la razón social del RUT.';
        }

        return [$out, $avisos];
    }

    // ------------------------------------------------------------ prompt

    private function camposActivos(array $c): array
    {
        return collect($c['campos'])->where('activo', true)->map(fn ($f) => ['clave' => $f['clave'], 'etiqueta' => $f['etiqueta'], 'obligatorio' => $f['obligatorio']])->values()->all();
    }

    private function describirReglas(array $f, array $m): string
    {
        $r = [];
        if (in_array($m['t'], self::TIPOS_LISTA, true)) {
            $r[] = $m['t'] === 'booleano' ? 'sí o no' : ($m['t'] === 'multilista' ? 'una o varias opciones del catálogo' : 'debe ser una opción del catálogo');
        } else {
            $r[] = 'tipo '.mb_strtolower(self::TIPOS[$f['tipo']] ?? $f['tipo']);
        }
        if ($f['min'] || $f['max']) {
            $r[] = 'longitud '.($f['min'] && $f['max'] ? "entre {$f['min']} y {$f['max']}" : ($f['min'] ? "mínimo {$f['min']}" : "máximo {$f['max']}")).' caracteres';
        }
        if ($f['unico']) {
            $r[] = 'único (no puede existir ya en el sistema)';
        }
        if ($f['patron']) {
            $r[] = "debe cumplir el patrón /{$f['patron']}/";
        }

        return implode(', ', $r);
    }

    public function prompt(array $c): string
    {
        $catalogo = $this->catalogo();
        $lineas = [];
        foreach (collect($c['campos'])->where('activo', true)->values() as $i => $f) {
            $m = $catalogo[$f['clave']];
            $l = ($i + 1).". `{$f['clave']}` — {$f['etiqueta']} · ".($f['obligatorio'] ? 'OBLIGATORIO' : 'opcional').'. Reglas: '.$this->describirReglas($f, $m).". Formato: {$m['f']}.";
            if (in_array($m['t'], ['lista', 'multilista'], true) && ! in_array($f['clave'], ['ciudad', 'departamento'], true)) {
                $op = array_values($this->opciones($f['clave']));
                if ($op && count($op) <= 30) {
                    $l .= ' Opciones: '.implode(' | ', $op).'.';
                }
            }
            if ($f['instrucciones']) {
                $l .= " Cómo pedirlo: {$f['instrucciones']}";
            }
            if ($f['validacion']) {
                $l .= " Validación adicional: {$f['validacion']}";
            }
            if ($f['web'] && $c['buscar_web']) {
                $l .= ' Si el usuario no lo sabe, puedes buscarlo en internet (fuentes oficiales o el sitio de la empresa) y proponérselo para que lo confirme.';
            }
            $lineas[] = $l;
        }

        $reglas = [
            $c['un_dato_a_la_vez'] ? 'Pide UN solo dato por mensaje, siguiendo el orden de la lista.' : 'Puedes pedir varios datos relacionados en un mismo mensaje, pero siguiendo el orden de la lista.',
            $c['aceptar_varios_datos'] ? 'Si el usuario da varios datos de una vez, extráelos todos y no se los vuelvas a pedir.' : 'Procesa un dato a la vez.',
            'Nunca inventes datos. Si no lo sabes o el usuario no lo dijo, déjalo fuera de "datos".',
            'No pidas datos que no estén en la lista. Los opcionales se ofrecen una sola vez; si el usuario los omite, sigue.',
            'El usuario puede estar dictando por voz: interpreta lo dictado y conviértelo al formato del dato (ej. "juan arroba empresa punto com" → juan@empresa.com; "novecientos millones…" o dígitos separados → NIT en dígitos; "guion" → -; "cero" → 0). Si hay duda sobre un número, un correo o un nombre, repítelo y pide confirmación antes de darlo por bueno.',
            'Respeta las reglas de cada dato (tipo, longitud, único, patrón): si el valor no las cumple, explícaselo y pide uno válido.',
            $c['confirmar_antes_de_crear'] ? 'Cuando tengas todos los obligatorios, muestra un resumen y pregunta si lo crea; solo entonces marca "listo": true.' : 'Cuando tengas todos los obligatorios marca "listo": true.',
            'El sistema te enviará al final del mensaje del usuario un bloque [ESTADO DEL SISTEMA] con lo ya recopilado, lo que falta y los errores o duplicados detectados: respétalo y no lo muestres literalmente.',
            'La creación real la hace el sistema, no tú. Nunca digas que ya creaste el tercero.',
        ];
        if ($c['reglas_extra']) {
            $reglas[] = $c['reglas_extra'];
        }

        return implode("\n\n", array_filter([
            $c['rol'],
            'Tono: '.$c['tono'],
            "DATOS A PEDIR, EN ESTE ORDEN:\n".implode("\n", $lineas),
            "REGLAS:\n- ".implode("\n- ", $reglas),
            "FORMATO DE RESPUESTA (obligatorio): responde SOLO con un objeto JSON, sin texto fuera de él ni bloques de código:\n"
            .'{"mensaje": "lo que le dices al usuario", "datos": {"clave": "valor"}, "listo": false}'."\n"
            .'"datos" contiene únicamente claves de la lista, con TODOS los valores conocidos hasta ahora (los de turnos anteriores incluidos) y corregidos si el usuario los cambió. En las listas de varias opciones, separa con coma. "mensaje" va en español, sin markdown.',
        ]));
    }

    private function bloqueEstado(array $e): string
    {
        return "[ESTADO DEL SISTEMA]\n".json_encode([
            'recopilado' => (object) $e['datos'], 'faltan_obligatorios' => $e['faltan'], 'errores_de_validacion' => (object) $e['errores'], 'avisos' => $e['avisos'],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function interpretar(string $texto): array
    {
        $t = trim(preg_replace('/^```(?:json)?|```$/m', '', $texto));
        $ini = strpos($t, '{');
        $fin = strrpos($t, '}');
        $j = ($ini !== false && $fin > $ini) ? json_decode(substr($t, $ini, $fin - $ini + 1), true) : null;
        if (! is_array($j)) {
            return ['mensaje' => $texto, 'datos' => [], 'listo' => false];
        }

        return ['mensaje' => trim((string) ($j['mensaje'] ?? '')), 'datos' => array_intersect_key((array) ($j['datos'] ?? []), $this->catalogo()), 'listo' => (bool) ($j['listo'] ?? false)];
    }

    // ------------------------------------------------------------ listas (catálogos de la ficha)

    /** @return array<string, array<int|string, string>> */
    private function listas(): array
    {
        return $this->listasMemo ??= Cache::remember('ia-terceros-listas', 300, function () {
            $d = $this->ficha->datosFicha($this->ficha->nuevo());
            $pares = fn ($col) => collect($col)->mapWithKeys(fn ($o) => [(string) (is_array($o) ? $o['id'] : $o->id) => (string) (is_array($o) ? $o['label'] : $o->label)])->all();

            return [
                'tipo_entidad' => $pares($d['tiposTercero']),
                'forma_juridica' => $pares($d['formasJuridicas']),
                'pais' => $pares($d['paises']),
                'nivel_precio' => array_map('strval', $d['nivelesPrecio']),
                'medio_pago' => $pares($d['mediosPago']),
                'condicion_pago' => $pares($d['terminosPago']),
                'categorias_cliente' => $pares($d['categoriasCliente']),
                'categorias_proveedor' => $pares($d['categoriasProveedor']),
            ];
        });
    }

    /** @return array<int|string, string> id => etiqueta */
    private function opciones(string $clave): array
    {
        $m = $this->catalogo()[$clave];
        if (isset($m['ef'])) {
            return array_map('strval', $this->extrafields->opciones($m['def']));
        }

        return match ($clave) {
            'tipo_tercero' => ['cliente' => 'cliente', 'proveedor' => 'proveedor', 'ambos' => 'ambos'],
            'tipo_documento' => TerceroFichaService::TIPOS_DOCUMENTO,
            default => $this->listas()[$clave] ?? [],
        };
    }

    private static function clave(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', Str::ascii($s))));
    }

    /** @return array{0: string|int|null, 1: ?string} id y etiqueta canónica de la opción que mejor coincide */
    private function buscarOpcion(array $opciones, string $valor): array
    {
        $v = self::clave($valor);
        foreach ($opciones as $id => $label) {
            if (self::clave((string) $id) === $v || self::clave($label) === $v) {
                return [$id, $label];
            }
        }
        $parecidas = array_filter($opciones, fn ($l) => str_contains(self::clave($l), $v) || ($v !== '' && str_contains($v, self::clave($l))));

        return count($parecidas) === 1 ? [array_key_first($parecidas), reset($parecidas)] : [null, null];
    }

    // ------------------------------------------------------------ normalización y validación (siempre en servidor)

    /**
     * Normaliza, valida y mapea a la ficha todo lo recopilado.
     *
     * @return array{datos: array, cols: array, ef: array, cats: array, faltan: array, errores: array, avisos: array}
     */
    private function procesar(array $c, array $datos): array
    {
        $catalogo = $this->catalogo();
        $campos = array_column($c['campos'], null, 'clave');
        $errores = [];
        $avisos = [];
        $cols = [];
        $ef = [];
        $cats = [];
        $limpios = [];

        foreach (array_intersect_key($datos, $campos) as $k => $v) {
            $f = $campos[$k];
            $m = $catalogo[$k];
            if (! $f['activo'] || ! is_scalar($v) || trim((string) $v) === '') {
                continue;
            }
            $v = trim(preg_replace('/\s+/', ' ', (string) $v));

            // ---- lista / booleano: se resuelve contra el catálogo y se guarda la etiqueta canónica
            if ($m['t'] === 'lista' && ! in_array($k, ['departamento', 'ciudad'], true) && ($this->opciones($k) || ! isset($m['ef']))) {
                [$id, $label] = $this->buscarOpcion($this->opciones($k), $v);
                if ($id === null) {
                    $errores[$k] = "\"{$v}\" no es una opción válida de {$f['etiqueta']}.";
                } else {
                    $limpios[$k] = $label;
                    $this->asignar($k, $m, $id, $cols, $ef);
                }

                continue;
            }
            if ($m['t'] === 'multilista') {
                $ids = [];
                $labels = [];
                foreach (array_filter(array_map('trim', preg_split('/[,;]/', $v))) as $parte) {
                    [$id, $label] = $this->buscarOpcion($this->opciones($k), $parte);
                    if ($id === null) {
                        $errores[$k] = "La categoría \"{$parte}\" no existe.";
                    } else {
                        $ids[] = (int) $id;
                        $labels[] = $label;
                    }
                }
                $limpios[$k] = implode(', ', $labels);
                $cats[$k] = $ids;

                continue;
            }
            if ($m['t'] === 'booleano') {
                $si = in_array(self::clave($v), ['si', 'sí', 's', '1', 'true', 'yes'], true);
                $limpios[$k] = $si ? 'Sí' : 'No';
                $ef[$m['ef']] = $si ? 1 : 0;

                continue;
            }

            // ---- valor libre: normalización por tipo y reglas
            $v = $this->normalizarTipo($k, $f, $c, $v);
            $limpios[$k] = $v;
            if ($err = $this->validarLibre($k, $f, $m, $v)) {
                $errores[$k] = $err;
            }
            if (! in_array($k, ['departamento', 'ciudad'], true)) {
                $this->asignar($k, $m, $v, $cols, $ef);
            }
        }

        // ---- tipo de tercero → client / fournisseur
        if (isset($limpios['tipo_tercero'])) {
            $t = $limpios['tipo_tercero'];
            $cols['client'] = in_array($t, ['cliente', 'ambos'], true) ? 1 : 0;
            $cols['fournisseur'] = in_array($t, ['proveedor', 'ambos'], true) ? 1 : 0;
        }

        // ---- documento: dígito de verificación del NIT y duplicados
        if (isset($limpios['documento'])) {
            $doc = $limpios['documento'];
            $tipo = $limpios['tipo_documento'] ?? null;
            if (preg_match('/^(\d{5,})-(\d)$/', $doc, $mm)) {
                if ($tipo === 'NIT' && (int) $mm[2] !== $this->digitoVerificacion($mm[1])) {
                    $errores['documento'] = "El dígito de verificación no coincide: para el NIT {$mm[1]} debería ser ".$this->digitoVerificacion($mm[1]).'.';
                }
                $limpios['documento'] = $cols['siren'] = $mm[1];
            }
            if ($tipo === 'NIT' && ctype_digit($limpios['documento']) && strlen($limpios['documento']) >= 8) {
                $avisos[] = 'DV calculado del NIT '.$limpios['documento'].': '.$this->digitoVerificacion($limpios['documento']).'.';
            }
        }
        if ($c['detectar_duplicados'] && isset($limpios['nom']) && ! ($campos['nom']['unico'] ?? false)) {
            $dup = Sociedad::query()->where('nom', $limpios['nom'])->first(['rowid', 'siren']);
            if ($dup) {
                $avisos[] = "Ya hay un tercero con el mismo nombre (id {$dup->rowid}, documento {$dup->siren}); confirma con el usuario que no es el mismo.";
            }
        }

        // ---- único: contra societe / societe_extrafields
        foreach ($limpios as $k => $v) {
            if (isset($errores[$k]) || ! ($campos[$k]['unico'] ?? false)) {
                continue;
            }
            if ($dup = $this->existente($k, $catalogo[$k], $v, $cols, $ef)) {
                $errores[$k] = "Ya existe otro tercero con ese valor de {$campos[$k]['etiqueta']}: {$dup}. Pregunta al usuario si se equivocó.";
            }
        }

        // ---- CIIU, ciudad y departamento contra los catálogos de la instalación
        if (isset($limpios['ape']) && ! isset($errores['ape']) && DB::connection('mysql')->getSchemaBuilder()->hasTable('c_codigos_ciiu')
            && ! DB::connection('mysql')->table('c_codigos_ciiu')->where('codigo', $limpios['ape'])->exists()) {
            $errores['ape'] = "El código CIIU {$limpios['ape']} no existe en el catálogo.";
        }
        [$geo, $errGeo, $nombresGeo] = $this->resolverGeografia($limpios);
        $cols += $geo;
        $errores += $errGeo;
        $limpios = array_merge($limpios, $nombresGeo);

        // ---- obligatorios que faltan
        $faltan = [];
        foreach ($c['campos'] as $f) {
            if ($f['activo'] && $f['obligatorio'] && ! isset($limpios[$f['clave']])) {
                $faltan[] = $f['clave'];
            }
        }

        // ---- reglas originales de la ficha sobre lo ya mapeado: si pasan, el tercero se puede crear
        $cols += ['status' => 1, 'tva_assuj' => 1];
        $reglas = [];
        foreach (self::SECCIONES_ORDEN as $sec) {
            $reglas += $this->ficha->reglas($this->ficha->nuevo(), $sec);
        }
        $aplicables = array_filter($reglas, fn ($_, $k) => array_key_exists($k, $cols) || (str_starts_with($k, 'ef.') && array_key_exists(substr($k, 3), $ef)), ARRAY_FILTER_USE_BOTH);
        $v = Validator::make($cols + ['ef' => $ef], $aplicables, $this->ficha->mensajes(), $this->ficha->atributos());
        if ($v->fails()) {
            $inverso = array_flip(self::COL) + ['fk_departement' => 'departamento', 'town' => 'ciudad'];
            foreach ($v->errors()->messages() as $col => $msgs) {
                $clave = str_starts_with($col, 'ef.') ? $col : ($inverso[$col] ?? $col);
                $errores[$clave] ??= $msgs[0];
            }
        }

        return ['datos' => $limpios, 'cols' => $cols, 'ef' => $ef, 'cats' => $cats, 'faltan' => $faltan, 'errores' => $errores, 'avisos' => $avisos];
    }

    /** Coloca el valor ya validado en la columna de societe o en el extrafield que le corresponde. */
    private function asignar(string $k, array $m, mixed $valor, array &$cols, array &$ef): void
    {
        if (isset($m['ef'])) {
            $ef[$m['ef']] = $valor;
        } elseif (isset(self::COL[$k])) {
            $cols[self::COL[$k]] = $valor;
        }
    }

    private function normalizarTipo(string $k, array $f, array $c, string $v): string
    {
        $tipo = $f['tipo'];
        if (in_array($k, ['nom', 'name_alias'], true) && $c['mayusculas_nombre']) {
            return mb_strtoupper($v);
        }

        return match (true) {
            $k === 'documento' => preg_replace('/[\s.]/', '', $v),
            $tipo === 'email' => mb_strtolower($v),
            $tipo === 'telefono' => preg_replace('/[^\d+]/', '', $v),
            $tipo === 'numerico' => preg_replace('/[\s.,]/', '', $v),
            $tipo === 'decimal' => str_replace(',', '.', preg_replace('/[\s$]/', '', $v)),
            $tipo === 'url' => preg_match('#^https?://#i', $v) ? $v : 'https://'.$v,
            $tipo === 'fecha' => ($t = strtotime($v)) ? date('Y-m-d', $t) : $v,
            default => $v,
        };
    }

    /** Reglas de tipo, longitud y patrón. Devuelve el mensaje de error o null. */
    private function validarLibre(string $k, array $f, array $m, string $v): ?string
    {
        $e = $f['etiqueta'];
        $tipo = $f['tipo'];
        $ok = match ($tipo) {
            'alfanumerico' => (bool) preg_match('/^[\pL\pN\- ]+$/u', $v),
            'numerico' => ctype_digit($v) || ($k === 'documento' && preg_match('/^\d+-\d$/', $v)),
            'entero' => (bool) preg_match('/^-?\d+$/', $v),
            'decimal' => is_numeric($v),
            'email' => (bool) filter_var($v, FILTER_VALIDATE_EMAIL),
            'telefono' => (bool) preg_match('/^\+?\d{7,15}$/', $v),
            'url' => (bool) filter_var($v, FILTER_VALIDATE_URL),
            'fecha' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false,
            default => true,
        };
        if (! $ok) {
            return match ($tipo) {
                'alfanumerico' => "{$e} solo admite letras y números.",
                'numerico' => "{$e} debe contener solo dígitos.",
                'entero' => "{$e} debe ser un número entero.",
                'decimal' => "{$e} debe ser un número.",
                'email' => "{$e} no es un correo válido.",
                'telefono' => "{$e} debe tener entre 7 y 15 dígitos.",
                'url' => "{$e} no es una URL válida.",
                default => "{$e} debe tener formato AAAA-MM-DD.",
            };
        }
        $largo = mb_strlen($k === 'documento' ? preg_replace('/-\d$/', '', $v) : $v);
        if ($f['min'] && $largo < $f['min']) {
            return "{$e} debe tener al menos {$f['min']} caracteres (tiene {$largo}).";
        }
        if ($f['max'] && $largo > $f['max']) {
            return "{$e} no puede superar {$f['max']} caracteres (tiene {$largo}).";
        }
        if ($k === 'descuento' && ((float) $v < 0 || (float) $v > 100)) {
            return 'El descuento debe estar entre 0 y 100.';
        }
        if ($k === 'limite_credito' && (float) $v < 0) {
            return 'El límite de crédito no puede ser negativo.';
        }
        if ($f['patron'] && @preg_match(self::regex($f['patron']), $v) !== 1) {
            return "{$e} no cumple el formato requerido ({$f['patron']}).";
        }

        return null;
    }

    /** Descripción del tercero que ya usa ese valor (null si es libre). */
    private function existente(string $k, array $m, string $valor, array $cols, array $ef): ?string
    {
        $db = DB::connection('mysql');
        if (isset($m['ef'])) {
            $id = $db->table('societe_extrafields')->where($m['ef'], $ef[$m['ef']] ?? $valor)->value('fk_object');
            $fila = $id ? $db->table('societe')->where('rowid', $id)->first(['rowid', 'nom']) : null;
        } elseif (isset(self::COL[$k])) {
            $fila = $db->table('societe')->where(self::COL[$k], $cols[self::COL[$k]] ?? $valor)->first(['rowid', 'nom']);
        } else {
            return null;
        }

        return $fila ? "{$fila->nom} (id {$fila->rowid})" : null;
    }

    private function digitoVerificacion(string $nit): int
    {
        $pesos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $suma = 0;
        foreach (array_reverse(str_split($nit)) as $i => $d) {
            $suma += (int) $d * ($pesos[$i] ?? 0);
        }
        $r = $suma % 11;

        return $r > 1 ? 11 - $r : $r;
    }

    /** @return array{0: array, 1: array<string, string>, 2: array<string, string>} columnas, errores y nombres canónicos */
    private function resolverGeografia(array $d): array
    {
        $cols = [];
        $err = [];
        $nombres = [];
        $db = DB::connection('mysql');
        $base = fn () => $db->table('c_departements as d')->join('c_regions as r', 'r.code_region', '=', 'd.fk_region')->where('r.fk_pays', 70);
        // Los nombres del RUT traen sufijos ("BOGOTÁ, D.C."): se prueba exacto, sin el sufijo y por coincidencia parcial.
        $variantes = fn (string $n) => array_values(array_unique(array_filter([$n, trim(preg_split('/[,(]/', $n)[0])])));
        if (isset($d['departamento'])) {
            $f = null;
            foreach ($variantes($d['departamento']) as $n) {
                $f = $base()->where('d.nom', 'like', $n)->first(['d.rowid', 'd.nom']) ?? $base()->where('d.nom', 'like', '%'.$n.'%')->first(['d.rowid', 'd.nom']);
                if ($f) {
                    break;
                }
            }
            if ($f) {
                $cols['fk_departement'] = (int) $f->rowid;
                $nombres['departamento'] = $f->nom;
            } else {
                $err['departamento'] = "No encuentro el departamento \"{$d['departamento']}\" en Colombia.";
            }
        }
        if (isset($d['ciudad'])) {
            $ciudades = collect();
            foreach ($variantes($d['ciudad']) as $n) {
                foreach ([$n, '%'.$n.'%'] as $patron) {
                    $q = $db->table('c_ciudades_fe')->where('active', 1)->where('ciudad', 'like', $patron);
                    if (isset($d['departamento'])) {
                        $q->where('depto', 'like', '%'.($variantes($d['departamento'])[0]).'%');
                    }
                    $ciudades = $q->limit(5)->get(['rowid', 'ciudad', 'depto']);
                    if ($ciudades->isNotEmpty()) {
                        break 2;
                    }
                }
            }
            if ($ciudades->count() === 1) {
                $cols['town'] = (int) $ciudades[0]->rowid;
                $nombres['ciudad'] = $ciudades[0]->ciudad;
            } elseif ($ciudades->count() > 1) {
                $err['ciudad'] = 'Hay varias ciudades con ese nombre ('.$ciudades->map(fn ($x) => "{$x->ciudad} - {$x->depto}")->implode('; ').'): indica el departamento.';
            } else {
                $err['ciudad'] = "No encuentro la ciudad \"{$d['ciudad']}\"".(isset($d['departamento']) ? " en {$d['departamento']}" : '').'.';
            }
        }

        return [$cols, $err, $nombres];
    }
}
