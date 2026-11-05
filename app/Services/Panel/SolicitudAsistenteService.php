<?php

namespace App\Services\Panel;

use App\Http\Controllers\Panel\Solicitudes\SolicitudesGridController;
use App\Models\Configuracion;
use App\Services\Ia\IaClient;
use App\Services\Ia\IaConfigService;
use App\Services\Ia\IaException;
use App\Services\Ia\SolicitudPromptsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Datos del asistente «Crear con asistente»: buscar el cliente y ver sus solicitudes, el historial de compras
 * del producto que busca y el análisis con IA (foto, referencia o descripción). La solicitud se crea con los
 * endpoints de la ficha; aquí solo se prepara la información para decidir.
 */
class SolicitudAsistenteService
{
    private string $p;

    public function __construct(
        private SolicitudFichaService $ficha,
        private SolicitudProductosIaService $productos,
        private ActividadesService $actividades,
        private IaClient $ia,
        private IaConfigService $iaConfig,
        private SolicitudPromptsService $prompts,
    ) {
        $this->p = DB::getTablePrefix();
    }

    /** Plantilla que el sistema anterior usa por defecto al crear una solicitud (se compara por nombre). */
    private const PLANTILLA_DEFECTO = 'respuesta solicitud cliente';

    /**
     * Lo necesario para armar el correo: plantillas del usuario, la que va por defecto, su firma (HTML limpio), su nombre y el
     * remitente configurado en Dolibarr.
     *
     * @return array{plantillas: array, plantilla_defecto: ?int, firma: string, asesor: ?string, de: ?string}
     */
    public function contextoCorreo(object $usuario): array
    {
        // Solo las plantillas (catalogos() trae además todos los clientes, que aquí no hacen falta).
        $plantillas = array_map(fn ($t) => ['id' => (int) $t->id, 'label' => (string) $t->label, 'topic' => (string) $t->topic, 'content' => (string) $t->content],
            DB::select("SELECT rowid id, label, topic, content FROM {$this->p}c_email_templates WHERE active = 1 AND (fk_user IS NULL OR fk_user = 0 OR fk_user = ?) ORDER BY position, label", [$usuario->getKey()]));
        $defecto = collect($plantillas)->first(fn ($t) => mb_strtolower(trim($t['label'])) === self::PLANTILLA_DEFECTO);
        $asesor = DB::selectOne("SELECT TRIM(CONCAT_WS(' ', firstname, lastname)) n FROM {$this->p}user WHERE rowid = ?", [$usuario->getKey()])->n ?? null;

        return [
            'plantillas' => $plantillas,
            'plantilla_defecto' => $defecto['id'] ?? null,
            'firma' => $this->ficha->firmaHtml($usuario),
            'asesor' => $asesor ?: null,
            'de' => Configuracion::valores(['MAIN_MAIL_EMAIL_FROM'])['MAIN_MAIL_EMAIL_FROM'] ?? null,
        ];
    }

    /** Clientes por nombre, NIT o código. */
    public function clientes(string $q, int $limite = 12): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
        $rows = DB::select("SELECT s.rowid, s.nom, s.tva_intra nit, s.town, s.code_client
            FROM {$this->p}societe s
            WHERE s.status = 1 AND s.client IN (1,2,3) AND (s.nom LIKE ? OR s.name_alias LIKE ? OR s.tva_intra LIKE ? OR s.code_client LIKE ?)
            ORDER BY s.nom LIMIT {$limite}", [$like, $like, $like, $like]);

        return array_map(fn ($c) => ['id' => (int) $c->rowid, 'nombre' => $c->nom, 'nit' => $c->nit, 'ciudad' => $c->town, 'codigo' => $c->code_client], $rows);
    }

    /**
     * Lo que hay que saber del cliente antes de pedir nada: sus solicitudes (con estado y mora), contactos y peso histórico.
     * Un usuario sin acceso total solo ve las solicitudes propias del cliente.
     */
    public function cliente(object $usuario, int $id): ?array
    {
        $soc = DB::selectOne("SELECT rowid, nom, tva_intra nit, town, phone, email FROM {$this->p}societe WHERE rowid = ?", [$id]);
        if (! $soc) {
            return null;
        }
        $duenio = $usuario->isAdmin() ? '' : ' AND t.'.$this->actividades->campoUsuario().' = '.(int) $usuario->getKey();
        $filas = DB::select("SELECT t.rowid, t.ref, t.asunto, t.status, t.fecha_solicitud, t.date_creation, t.fecha_proximo_contacto,
                DATEDIFF(CURDATE(), DATE(t.date_creation)) mora
            FROM {$this->p}solicitudes_solicitud t WHERE t.fk_soc = ? AND t.status > 0{$duenio}
            ORDER BY COALESCE(t.fecha_solicitud, t.date_creation) DESC, t.rowid DESC LIMIT 40", [$id]);

        $solicitudes = array_map(fn ($t) => [
            'id' => (int) $t->rowid,
            'ref' => $t->ref,
            'asunto' => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $t->asunto), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            'estado' => (int) $t->status,
            'estadoNombre' => SolicitudesGridController::ESTADOS[(string) $t->status] ?? 'Estado '.$t->status,
            'fecha' => substr((string) ($t->fecha_solicitud ?: $t->date_creation), 0, 10),
            'proximoContacto' => $t->fecha_proximo_contacto ? substr((string) $t->fecha_proximo_contacto, 0, 10) : null,
            'mora' => max(0, (int) $t->mora),
            'abierta' => in_array((int) $t->status, [1, 2, 3, 4, 5, 6], true),
        ], $filas);

        return [
            'cliente' => ['id' => (int) $soc->rowid, 'nombre' => $soc->nom, 'nit' => $soc->nit, 'ciudad' => $soc->town, 'telefono' => $soc->phone, 'email' => $soc->email],
            'solicitudes' => $solicitudes,
            'colores' => $this->coloresTipos(),
            'contactos' => $this->ficha->contactos($id),
            'resumen' => $this->actividades->resumenCliente($id),
        ] + (function () use ($usuario, $id) {
            $pz = $this->piezasCliente($usuario, $id, 0, self::PIEZAS_PAGINA);

            return ['piezas' => $pz['items'], 'piezas_mas' => $pz['mas']];
        })();
    }

    /** Efectividad del cliente en el periodo: `1m`, `3m`, `6m` (hasta hoy) o `rango` con fechas Y-m-d. */
    public function efectividad(int $id, string $periodo, ?string $desde = null, ?string $hasta = null): array
    {
        $hoy = \Illuminate\Support\Carbon::today();
        if ($periodo === 'rango') {
            $a = \Illuminate\Support\Carbon::parse($desde ?: $hoy->copy()->subMonths(3))->startOfDay();
            $b = \Illuminate\Support\Carbon::parse($hasta ?: $hoy)->startOfDay();
            if ($a->gt($b)) {
                [$a, $b] = [$b, $a];
            }
        } else {
            $meses = ['1m' => 1, '3m' => 3, '6m' => 6][$periodo] ?? 3;
            [$a, $b] = [$hoy->copy()->subMonths($meses)->addDay(), $hoy];
        }

        return $this->actividades->efectividadCliente($id, $a, $b) + ['periodo' => $periodo];
    }

    /** Color de cada tipo (solicitud, cotización, orden de compra): el elegido en Configuración → Panel o, si no, el de su card del tablero. */
    private function coloresTipos(): array
    {
        $pr = app(ProcesoComercialService::class);
        $propios = collect($pr->config())->keyBy('clave');
        $defecto = $pr->coloresPorDefecto();
        $fallback = ['solicitud' => '#1e3a8a', 'cotizacion' => '#ea580c', 'orden' => '#064e3b'];
        $out = [];
        foreach ($fallback as $k => $f) {
            $out[$k] = $propios->get($k)['color'] ?? $defecto[$k] ?? $f;
        }

        return $out;
    }

    private const PIEZAS_PAGINA = 5;

    /** Tipo de documento → grupo que se resalta: lo pedido (solicitud), lo cotizado y lo vendido (orden de compra). */
    private const GRUPOS_PIEZA = ['Solicitud' => 'solicitud', 'Cotización' => 'cotizado', 'Orden' => 'vendido'];

    /**
     * Piezas que el cliente ya ha pedido (solicitudes), le hemos cotizado y le hemos vendido (órdenes de compra), de la más reciente a la más
     * antigua. Un usuario sin acceso total solo ve lo suyo. Con `$busca` solo las que coinciden con esa pieza (producto, referencia o texto).
     *
     * @param  ?array{referencia?: ?string, fk_product?: ?int}  $busca
     * @return array{items: array<int, array<string, mixed>>, mas: bool}
     */
    public function piezasCliente(object $usuario, int $fkSoc, int $desde = 0, int $limite = 5, ?array $busca = null): array
    {
        $uf = $this->actividades->campoUsuario();
        $d = fn (string $alias) => $usuario->isAdmin() ? '' : " AND {$alias}.{$uf} = ".(int) $usuario->getKey();
        $tope = $desde + $limite + 1;

        $ref = trim((string) ($busca['referencia'] ?? ''));
        $prod = (int) ($busca['fk_product'] ?? 0);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ref).'%';
        $condLinea = '';
        $condOrden = '';
        $bLinea = [];
        $bOrden = [];
        if ($busca !== null) {
            $partesL = [];
            $partesO = [];
            if ($prod) {
                $partesL[] = 'sl.fk_product = ?';
                $partesO[] = 'pt.fk_product = ?';
                $bLinea[] = $prod;
                $bOrden[] = $prod;
            }
            if (mb_strlen($ref) >= 3) {
                $partesL[] = '(sl.model LIKE ? OR sl.description LIKE ? OR pr.ref LIKE ?)';
                $partesO[] = '(pr.ref LIKE ? OR pr.label LIKE ?)';
                array_push($bLinea, $like, $like, $like);
                array_push($bOrden, $like, $like);
            }
            if (! $partesL) {
                return ['items' => [], 'mas' => false];
            }
            $condLinea = ' AND ('.implode(' OR ', $partesL).')';
            $condOrden = ' AND ('.implode(' OR ', $partesO).')';
        }

        $p = $this->p;
        $columnas = 'sl.description descripcion, sl.model modelo, sl.fk_product, sl.qty cantidad, pr.ref prod_ref, pr.label prod_label';
        $consultas = [
            ['Solicitud', "SELECT t.rowid doc_id, t.ref, COALESCE(t.fecha_solicitud, t.date_creation) fecha, t.status estado, {$columnas}, NULL valor, NULL tipo_moneda, NULL trm
                FROM {$p}solicitudes_solicitudline sl JOIN {$p}solicitudes_solicitud t ON t.rowid = sl.fk_solicitud LEFT JOIN {$p}product pr ON pr.rowid = sl.fk_product
                WHERE t.fk_soc = ? AND t.status > 0 AND sl.fk_cotizacion IS NULL{$d('t')}{$condLinea} ORDER BY fecha DESC, t.rowid DESC LIMIT {$tope}", array_merge([$fkSoc], $bLinea)],
            ['Cotización', "SELECT cc.rowid doc_id, cc.ref, DATE(cc.fecha) fecha, cc.status estado, {$columnas}, sl.subprice valor, cc.tipo_moneda, cc.trm
                FROM {$p}solicitudes_solicitudline sl JOIN {$p}cotizaciones_clientecotizacion cc ON cc.rowid = sl.fk_cotizacion LEFT JOIN {$p}product pr ON pr.rowid = sl.fk_product
                WHERE cc.fk_soc = ? AND cc.status > 0{$d('cc')}{$condLinea} ORDER BY cc.fecha DESC, cc.rowid DESC LIMIT {$tope}", array_merge([$fkSoc], $bLinea)],
            ['Orden', "SELECT o.rowid doc_id, o.ref, DATE(o.date_creation) fecha, o.status estado, pr.label descripcion, NULL modelo, pt.fk_product, pt.cantidad, pr.ref prod_ref, pr.label prod_label, pt.precio valor, cc.tipo_moneda, cc.trm
                FROM {$p}ordencompra_product_trm pt JOIN {$p}ordencompra_orden o ON o.rowid = pt.fk_orden LEFT JOIN {$p}product pr ON pr.rowid = pt.fk_product
                LEFT JOIN {$p}cotizaciones_clientecotizacion cc ON cc.rowid = o.fk_cliente_cotizacion
                WHERE o.fk_soc = ? AND o.status NOT IN (0,9,10,31){$d('o')}{$condOrden} ORDER BY o.date_creation DESC, o.rowid DESC LIMIT {$tope}", array_merge([$fkSoc], $bOrden)],
        ];

        $filas = [];
        foreach ($consultas as [$tipo, $sql, $bind]) {
            try {
                foreach (DB::select($sql, $bind) as $r) {
                    $filas[] = $this->filaPieza($tipo, $r);
                }
            } catch (Throwable) {
                // esta instalación no tiene esa tabla o columna
            }
        }
        usort($filas, fn ($a, $b) => strcmp((string) $b['fecha'], (string) $a['fecha']) ?: strcmp($b['ref'], $a['ref']));

        return ['items' => array_slice($filas, $desde, $limite), 'mas' => count($filas) > $desde + $limite];
    }

    private function filaPieza(string $tipo, object $r): array
    {
        $estados = $tipo === 'Solicitud' ? SolicitudesGridController::ESTADOS : ProcesoComercialService::ESTADOS[$tipo === 'Orden' ? 'orden' : 'cotizacion'];
        $texto = fn ($v, int $max = 160) => mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, $max);
        $moneda = [1 => 'USD', 2 => 'COP'][(int) $r->tipo_moneda] ?? null;

        return [
            'tipo' => $tipo,
            'grupo' => self::GRUPOS_PIEZA[$tipo],
            'doc_id' => (int) $r->doc_id,
            'ref' => (string) $r->ref,
            'fecha' => $r->fecha ? substr((string) $r->fecha, 0, 10) : null,
            'estado' => (int) $r->estado,
            'estadoNombre' => $estados[(string) $r->estado] ?? $estados[(int) $r->estado] ?? 'Estado '.$r->estado,
            'descripcion' => $texto($r->descripcion ?: $r->prod_label),
            'modelo' => $texto($r->modelo, 60),
            'prod_ref' => $texto($r->prod_ref, 60),
            'fk_product' => $r->fk_product ? (int) $r->fk_product : null,
            'cantidad' => (float) $r->cantidad,
            'valor' => $r->valor !== null && (float) $r->valor > 0 ? (float) $r->valor : null,
            'moneda' => $moneda,
        ];
    }

    /**
     * Para cada pieza que se está buscando, dónde aparece ya en el historial del cliente (solicitudes, cotizaciones, órdenes).
     *
     * @param  array<int, array{id: string, referencia?: ?string, fk_product?: ?int}>  $piezas
     * @return array<string, array<int, array<string, mixed>>>  por id de pieza
     */
    public function coincidencias(object $usuario, int $fkSoc, array $piezas): array
    {
        $out = [];
        foreach (array_slice($piezas, 0, 10) as $pz) {
            $out[(string) $pz['id']] = $this->piezasCliente($usuario, $fkSoc, 0, 8, ['referencia' => $pz['referencia'] ?? null, 'fk_product' => $pz['fk_product'] ?? null])['items'];
        }

        return $out;
    }

    /**
     * Historial interno del elemento: quién lo ha comprado (cotizaciones y órdenes, con fecha, precio y estado) y con qué
     * proveedores se ha comprado. Sin producto del catálogo se intenta emparejar por referencia exacta.
     *
     * @return array{fk_product: ?int, producto: ?array, comprado: bool, compras: array, resumen: ?array, proveedores: array}
     */
    public function historial(?int $fkProduct, ?string $referencia): array
    {
        $producto = null;
        if (! $fkProduct && trim((string) $referencia) !== '') {
            $r = DB::selectOne("SELECT rowid FROM {$this->p}product WHERE ref = ? LIMIT 1", [trim($referencia)]);
            $fkProduct = $r ? (int) $r->rowid : null;
        }
        if ($fkProduct) {
            $producto = DB::selectOne("SELECT rowid id, ref, label FROM {$this->p}product WHERE rowid = ?", [$fkProduct]);
            $fkProduct = $producto ? (int) $producto->id : null;
        }
        if (! $fkProduct) {
            return ['fk_product' => null, 'producto' => null, 'comprado' => false, 'compras' => [], 'resumen' => null, 'proveedores' => []];
        }

        $h = $this->productos->historial([$fkProduct])[$fkProduct] ?? ['precios' => [], 'resumen' => null];
        $compras = array_map(function ($c) {
            $estados = ProcesoComercialService::ESTADOS[$c['tipo'] === 'Orden' ? 'orden' : 'cotizacion'];

            return $c + ['estadoNombre' => $estados[$c['estado']] ?? 'Estado '.$c['estado']];
        }, array_slice($h['precios'], 0, 25));

        return [
            'fk_product' => $fkProduct,
            'producto' => ['id' => $fkProduct, 'ref' => $producto->ref, 'label' => $producto->label],
            'comprado' => $h['precios'] !== [],
            'compras' => $compras,
            'resumen' => $h['resumen'],
            'proveedores' => $this->proveedoresDelProducto($fkProduct),
        ];
    }

    /** Proveedores del catálogo y de las órdenes a proveedor de Dolibarr; cada consulta falla en silencio si la tabla no existe. */
    private function proveedoresDelProducto(int $id): array
    {
        $out = [];
        $consultas = [
            ['Precio de catálogo', "SELECT s.nom proveedor, pf.unitprice valor, pf.quantity qty, DATE(pf.tms) fecha, pf.ref_fourn ref
                FROM {$this->p}product_fournisseur_price pf JOIN {$this->p}societe s ON s.rowid = pf.fk_soc
                WHERE pf.fk_product = ? ORDER BY pf.tms DESC LIMIT 10"],
            ['Orden a proveedor', "SELECT s.nom proveedor, d.subprice valor, d.qty, DATE(c.date_commande) fecha, c.ref
                FROM {$this->p}commande_fournisseurdet d
                JOIN {$this->p}commande_fournisseur c ON c.rowid = d.fk_commande JOIN {$this->p}societe s ON s.rowid = c.fk_soc
                WHERE d.fk_product = ? ORDER BY c.date_commande DESC LIMIT 10"],
        ];
        foreach ($consultas as [$origen, $sql]) {
            try {
                foreach (DB::select($sql, [$id]) as $r) {
                    $out[] = ['origen' => $origen, 'proveedor' => $r->proveedor, 'precio' => (float) $r->valor, 'cantidad' => (float) $r->qty, 'fecha' => $r->fecha, 'ref' => $r->ref];
                }
            } catch (Throwable) {
                // esta instalación no tiene esas tablas
            }
        }

        return $out;
    }

    /**
     * Análisis con IA del elemento. `$e`: referencia, nombre, descripcion, cantidad, fk_product, cliente.
     * `$foto`: imagen en base64 (jpeg/png/webp) ya reducida por el navegador.
     *
     * @return array{identificacion: array, proveedores: array, recomendacion: ?array, alertas: array}
     *
     * @throws IaException
     */
    public function analizar(array $e, ?array $foto, array $historial): array
    {
        $envio = [
            'referencia' => $e['referencia'] ?? null,
            'nombre' => $e['nombre'] ?? null,
            'descripcion_del_cliente' => $e['descripcion'] ?? null,
            'cantidad' => $e['cantidad'] ?? null,
            'cliente' => $e['cliente'] ?? null,
            'foto_adjunta' => $foto !== null,
            'historial_interno' => [
                'ya_comprado' => $historial['comprado'],
                'producto_del_catalogo' => $historial['producto'],
                'compras' => array_map(fn ($c) => array_intersect_key($c, array_flip(['tipo', 'ref', 'tercero', 'fecha', 'estadoNombre', 'cantidad', 'moneda', 'valor', 'usd', 'cop'])), array_slice($historial['compras'], 0, 12)),
                'resumen_precios' => $historial['resumen'],
                'proveedores_conocidos' => array_slice($historial['proveedores'], 0, 12),
            ],
        ];
        $mensajes = [['role' => 'user', 'content' => json_encode($envio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]];
        $adjuntos = $foto ? [['mime' => $foto['mime'], 'data' => $foto['data'], 'nombre' => 'elemento']] : [];
        $json = null;

        // Si la respuesta llega cortada o sin JSON válido, se reintenta una vez con más espacio.
        foreach ([8000, 12000] as $intento => $tope) {
            try {
                $texto = $this->ia->chat($this->iaConfig->predeterminado(), $this->prompts->sistema('asistente'), $mensajes, true, true, $adjuntos, $tope);
            } catch (IaException $e) {
                if ($intento === 1 || ! str_contains($e->getMessage(), 'se cortó')) {
                    throw $e;
                }
                continue;
            }
            $ini = strpos($texto, '{');
            $fin = strrpos($texto, '}');
            $json = $ini !== false && $fin !== false ? json_decode(substr($texto, $ini, $fin - $ini + 1), true) : null;
            if (is_array($json)) {
                break;
            }
            Log::warning('IA analizar: respuesta sin JSON válido', ['intento' => $intento + 1, 'largo' => strlen($texto), 'inicio' => mb_substr($texto, 0, 300), 'fin' => mb_substr($texto, -200)]);
        }
        if (! is_array($json)) {
            throw new IaException('La IA no devolvió una respuesta válida. Inténtalo de nuevo.');
        }

        return $this->normalizar($json);
    }

    /** Instrucción fija (no editable) para entender el texto libre del asesor; el formato JSON lo exige el código. */
    private const SISTEMA_INTERPRETE = 'Eres el asistente que llena una solicitud de cotización de una empresa industrial colombiana a partir de lo que dice el asesor, escrito o dictado por voz '
        .'(puede traer errores de transcripción y mayúsculas). Extrae SOLO lo que el asesor menciona; lo que no mencione va null o vacío. Recibes el estado actual del formulario para entender correcciones '
        .'como «cambia el contacto a…» o «agrega otro producto…». No inventes referencias, nombres ni cantidades. Cada producto es un elemento: "referencia" es el código, modelo o serie del fabricante tal como lo dijo '
        .'y "nombre" el nombre genérico en español con la marca si la dijo; lo demás va en "descripcion". Si varios productos vienen unidos con «y» o comas, sepáralos. La cantidad es 1 si no la dice. '
        .'Ejemplo: «para el cliente BAVARIA & CIA S C A el producto BANDA MODULAR MCC FG500XLG B=1020 SERIE 500 y MEDIDOR DE FLUJO KROHNE MODELO BATCHFLUX 5500C para el contacto LUIS ALEXANDER ALFONSO» → '
        .'cliente "BAVARIA & CIA S C A", contacto "LUIS ALEXANDER ALFONSO", elementos [{"referencia":"FG500XLG B=1020 SERIE 500","nombre":"Banda modular MCC","descripcion":"","cantidad":1},'
        .'{"referencia":"BATCHFLUX 5500C","nombre":"Medidor de flujo Krohne","descripcion":"","cantidad":1}]. '
        .'Responde SOLO con JSON: {"cliente":null,"contacto":null,"elementos":[{"referencia":"","nombre":"","descripcion":"","cantidad":1}],"modo_elementos":"agregar","asunto":null,"dias_proximo_contacto":null,"mensaje":""}. '
        .'"modo_elementos" es "reemplazar" solo si el asesor pide cambiar o rehacer los productos; si no, "agregar". "mensaje" es una confirmación de una frase en español de lo que entendiste; llama «piezas» a los productos.';

    /**
     * Entiende un texto libre (escrito o dictado) y lo convierte en datos de la solicitud: cliente (buscado en la base),
     * texto del contacto (se empareja en el navegador con los contactos del cliente), elementos, asunto y próximo contacto.
     *
     * @param  array{cliente?: ?string, contacto?: ?string, elementos?: array}  $estado  lo que ya tiene el formulario
     * @return array<string, mixed>
     *
     * @throws IaException
     */
    public function interpretar(string $texto, array $estado): array
    {
        $respuesta = $this->ia->chat(
            $this->iaConfig->predeterminado(),
            self::SISTEMA_INTERPRETE,
            [['role' => 'user', 'content' => json_encode(['texto_del_asesor' => $texto, 'estado_actual' => $estado], JSON_UNESCAPED_UNICODE)]],
            false, true, [], 1500,
        );
        $ini = strpos($respuesta, '{');
        $fin = strrpos($respuesta, '}');
        $j = $ini !== false && $fin !== false ? json_decode(substr($respuesta, $ini, $fin - $ini + 1), true) : null;
        if (! is_array($j)) {
            throw new IaException('No pude entender el mensaje. Intenta decirlo de otra forma.');
        }

        $txt = fn ($v, int $max = 200) => is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
        $elementos = [];
        foreach (array_slice((array) ($j['elementos'] ?? []), 0, 10) as $e) {
            if (! is_array($e)) {
                continue;
            }
            $el = ['referencia' => $txt($e['referencia'] ?? '', 100), 'nombre' => $txt($e['nombre'] ?? '', 200), 'descripcion' => $txt($e['descripcion'] ?? '', 1000),
                'cantidad' => is_numeric($e['cantidad'] ?? null) && (float) $e['cantidad'] > 0 ? (float) $e['cantidad'] : 1];
            if ($el['referencia'] !== '' || $el['nombre'] !== '' || $el['descripcion'] !== '') {
                $elementos[] = $el;
            }
        }
        $clienteTexto = $txt($j['cliente'] ?? '');
        $dias = is_numeric($j['dias_proximo_contacto'] ?? null) ? max(0, min(365, (int) $j['dias_proximo_contacto'])) : null;

        return [
            'cliente_texto' => $clienteTexto ?: null,
            'cliente' => $clienteTexto !== '' ? $this->resolverCliente($clienteTexto) : null,
            'contacto_texto' => $txt($j['contacto'] ?? '') ?: null,
            'elementos' => $elementos,
            'modo_elementos' => ($j['modo_elementos'] ?? '') === 'reemplazar' ? 'reemplazar' : 'agregar',
            'asunto' => $txt($j['asunto'] ?? '', 150) ?: null,
            'dias_proximo_contacto' => $dias,
            'mensaje' => $txt($j['mensaje'] ?? '', 300),
        ];
    }

    /**
     * Cliente que más se parece al texto dicho. Se buscan sus palabras sin puntuación ni siglas sueltas («BAVARIA & CIA S C A»
     * encuentra «BAVARIA & CIA S.C.A.»). Devuelve el elegido cuando hay una coincidencia clara o, si no, los candidatos.
     *
     * @return array{elegido: ?array, candidatos: array}
     */
    private function resolverCliente(string $texto): array
    {
        $norm = fn (string $v) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($v))));
        $palabras = array_values(array_unique(array_filter(explode(' ', $norm($texto)), fn ($w) => strlen($w) >= 3)));
        usort($palabras, fn ($a, $b) => strlen($b) <=> strlen($a));
        if (! $palabras) {
            return ['elegido' => null, 'candidatos' => []];
        }

        $buscar = function (array $ws) {
            $where = implode(' AND ', array_fill(0, count($ws), '(s.nom LIKE ? OR s.name_alias LIKE ?)'));
            $b = [];
            foreach ($ws as $w) {
                array_push($b, "%$w%", "%$w%");
            }

            return DB::select("SELECT s.rowid, s.nom, s.tva_intra nit, s.town FROM {$this->p}societe s WHERE s.status = 1 AND s.client IN (1,2,3) AND $where ORDER BY s.nom LIMIT 25", $b);
        };
        $filas = $buscar(array_slice($palabras, 0, 4));
        if (! $filas && count($palabras) > 1) {
            $filas = $buscar(array_slice($palabras, 0, 1)); // la palabra más larga sola
        }

        $consulta = $norm($texto);
        $puntuados = [];
        foreach ($filas as $f) {
            similar_text($consulta, $norm($f->nom), $pct);
            $puntuados[] = ['id' => (int) $f->rowid, 'nombre' => $f->nom, 'nit' => $f->nit, 'ciudad' => $f->town, 'puntaje' => round($pct, 1)];
        }
        usort($puntuados, fn ($a, $b) => $b['puntaje'] <=> $a['puntaje']);
        $top = array_slice($puntuados, 0, 5);
        $claro = $top && (count($top) === 1 || ($top[0]['puntaje'] >= 80 && $top[0]['puntaje'] - $top[1]['puntaje'] >= 8));

        return ['elegido' => $claro ? $top[0] : null, 'candidatos' => $claro ? [] : $top];
    }

    /** Deja la respuesta de la IA con tipos y largos conocidos; la url solo vale si es http(s). */
    private function normalizar(array $j): array
    {
        $txt = fn ($v, int $max = 300) => is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
        $nota = function ($v) {
            $n = is_numeric($v) ? (int) round((float) $v) : null;

            return $n !== null && $n >= 1 && $n <= 5 ? $n : null;
        };
        $i = is_array($j['identificacion'] ?? null) ? $j['identificacion'] : [];
        $ident = [
            'nombre' => $txt($i['nombre'] ?? '', 200), 'marca' => $txt($i['marca'] ?? '', 100), 'referencia' => $txt($i['referencia'] ?? '', 100),
            'parte' => $txt($i['parte'] ?? '', 150), 'categoria' => $txt($i['categoria'] ?? '', 100), 'descripcion' => $txt($i['descripcion'] ?? '', 800),
            'confianza' => in_array($i['confianza'] ?? '', ['alta', 'media', 'baja'], true) ? $i['confianza'] : null,
            'especificaciones' => [],
        ];
        foreach (array_slice((array) ($i['especificaciones'] ?? []), 0, 8) as $esp) {
            if (is_array($esp) && $txt($esp['campo'] ?? '', 60) !== '') {
                $ident['especificaciones'][] = ['campo' => $txt($esp['campo'], 60), 'valor' => $txt($esp['valor'] ?? '', 160)];
            }
        }

        $provs = [];
        foreach (array_slice((array) ($j['proveedores'] ?? []), 0, 5) as $o) {
            if (! is_array($o) || $txt($o['proveedor'] ?? '', 150) === '') {
                continue;
            }
            $url = $txt($o['url'] ?? '', 500);
            $pt = is_array($o['puntaje'] ?? null) ? $o['puntaje'] : [];
            $provs[] = [
                'proveedor' => $txt($o['proveedor'], 150), 'pais' => $txt($o['pais'] ?? '', 60),
                'origen' => ($o['origen'] ?? '') === 'historial' ? 'historial' : 'web',
                'comprado_antes' => (bool) ($o['comprado_antes'] ?? false),
                'precio' => $txt($o['precio'] ?? '', 100), 'url' => preg_match('#^https?://#i', $url) ? $url : '',
                'tiempo_entrega' => $txt($o['tiempo_entrega'] ?? '', 120), 'transporte' => $txt($o['transporte'] ?? '', 200), 'cumplimiento' => $txt($o['cumplimiento'] ?? '', 200),
                'puntaje' => ['costo' => $nota($pt['costo'] ?? null), 'cumplimiento' => $nota($pt['cumplimiento'] ?? null), 'transporte' => $nota($pt['transporte'] ?? null)],
                'nota' => $txt($o['nota'] ?? '', 300),
            ];
        }

        $r = is_array($j['recomendacion'] ?? null) ? $j['recomendacion'] : [];
        $recomendacion = $txt($r['proveedor'] ?? '', 150) !== '' || $txt($r['motivo'] ?? '', 600) !== ''
            ? ['proveedor' => $txt($r['proveedor'] ?? '', 150), 'motivo' => $txt($r['motivo'] ?? '', 600)] : null;

        return [
            'identificacion' => $ident,
            'proveedores' => $provs,
            'recomendacion' => $recomendacion,
            'alertas' => array_values(array_filter(array_map(fn ($a) => $txt($a, 250), array_slice((array) ($j['alertas'] ?? []), 0, 5)))),
        ];
    }
}
