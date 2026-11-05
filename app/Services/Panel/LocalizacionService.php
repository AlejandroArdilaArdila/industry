<?php

namespace App\Services\Panel;

use App\Http\Controllers\Panel\Solicitudes\SolicitudesGridController;
use App\Services\Correo\CorreoService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Paso «Localización» del proceso comercial: de una solicitud toma los productos que hay que cotizar y se los pide por
 * correo a uno o varios proveedores. Cada envío queda como en el sistema anterior: una cotización exterior (cotiext_exterior)
 * de la solicitud con un destinatario (cotiext_destinatarios) por cada correo enviado.
 *
 * El «ya se compró a este proveedor» sale de las órdenes de compra: informessolu_olimpiadas guarda por orden el nombre del
 * proveedor y informessolu_olimpiadas_recompras la relaciona con la línea de solicitud (y con ella, el producto). Además, la
 * marca del producto (c_marca_proveedor) apunta al proveedor habitual.
 */
class LocalizacionService
{
    /** Estados en los que una solicitud todavía se está localizando: activa, en solicitud exterior, local o USA. */
    public const ESTADOS = [1, 2, 3, 6];

    private const PAIS_LOCAL = 70; // Colombia

    /** Días sin respuesta tras el último correo a proveedores para pasar a «Sin respuesta». */
    public const DIAS_ESPERA = 3;

    public const POR_COLUMNA = 25;

    /** El tablero solo trae solicitudes de los últimos meses: las más viejas que quedaron abiertas son historia, no trabajo del día. */
    public const VENTANA_MESES = 6;

    /** Columnas del tablero, de la más a la menos urgente. */
    public const COLUMNAS = [
        'sinrespuesta' => ['titulo' => 'Sin respuesta', 'icono' => 'fa-hourglass-end', 'color' => '#dc2626', 'ayuda' => 'Más de 3 días desde el último correo a proveedores'],
        'porpedir' => ['titulo' => 'Por pedir cotización', 'icono' => 'fa-paper-plane', 'color' => '#2563eb', 'ayuda' => 'Con productos y aún sin correo a proveedores'],
        'esperando' => ['titulo' => 'Esperando proveedores', 'icono' => 'fa-hourglass-half', 'color' => '#0891b2', 'ayuda' => 'Correo enviado hace 3 días o menos'],
        'sinproductos' => ['titulo' => 'Sin productos', 'icono' => 'fa-box-open', 'color' => '#64748b', 'ayuda' => 'Falta agregar los productos a la solicitud'],
    ];

    private string $p;

    public function __construct(private SolicitudFichaService $ficha, private ActividadesService $actividades, private CorreoService $correo)
    {
        $this->p = DB::getTablePrefix();
    }

    // ------------------------------------------------------------ listado

    /** @return array{total: int, items: array<int, array<string, mixed>>} */
    public function listado(object $usuario, string $q, ?int $estado, int $skip = 0): array
    {
        $estados = $estado && in_array($estado, self::ESTADOS, true) ? [$estado] : self::ESTADOS;
        $where = 't.status IN ('.implode(',', $estados).')';
        $b = [];
        if (! $usuario->isAdmin()) {
            $where .= ' AND t.'.$this->actividades->campoUsuario().' = ?';
            $b[] = $usuario->getKey();
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $where .= ' AND (t.ref LIKE ? OR s.nom LIKE ? OR t.asunto LIKE ?)';
            array_push($b, $like, $like, $like);
        }
        $desde = "FROM {$this->p}solicitudes_solicitud t LEFT JOIN {$this->p}societe s ON s.rowid = t.fk_soc";
        $total = (int) (DB::selectOne("SELECT COUNT(*) n $desde WHERE $where", $b)->n ?? 0);
        $filas = DB::select("SELECT t.rowid, t.ref, s.nom cliente, t.asunto, t.status, t.date_creation,
                DATEDIFF(CURDATE(), DATE(t.date_creation)) dias,
                (SELECT COUNT(*) FROM {$this->p}solicitudes_solicitudline l WHERE l.fk_solicitud = t.rowid AND l.fk_cotizacion IS NULL) productos,
                (SELECT COUNT(*) FROM {$this->p}cotiext_destinatarios d JOIN {$this->p}cotiext_exterior x ON x.rowid = d.fk_cotizacion_exterior WHERE x.fk_solicitud = t.rowid) correos,
                (SELECT MAX(d.date_creation) FROM {$this->p}cotiext_destinatarios d JOIN {$this->p}cotiext_exterior x ON x.rowid = d.fk_cotizacion_exterior WHERE x.fk_solicitud = t.rowid) ultimo_envio
            $desde WHERE $where ORDER BY (correos > 0), t.rowid DESC LIMIT 60 OFFSET ".max(0, $skip), $b);

        return ['total' => $total, 'items' => array_map(fn ($o) => [
            'id' => (int) $o->rowid,
            'ref' => $o->ref,
            'cliente' => $o->cliente,
            'asunto' => self::texto($o->asunto),
            'estado' => (int) $o->status,
            'estadoNombre' => SolicitudesGridController::ESTADOS[(string) $o->status] ?? 'Estado '.$o->status,
            'dias' => max(0, (int) $o->dias),
            'productos' => (int) $o->productos,
            'correos' => (int) $o->correos,
            'ultimoEnvio' => $o->ultimo_envio ? substr((string) $o->ultimo_envio, 0, 10) : null,
        ], $filas)];
    }

    /** Definición para el cliente (sin datos): columnas y estados para filtrar. */
    public function definicion(): array
    {
        $columnas = [];
        foreach (self::COLUMNAS as $clave => $c) {
            $columnas[] = ['clave' => $clave] + $c;
        }

        return ['columnas' => $columnas, 'estados' => array_map(fn ($e) => ['id' => $e, 'nombre' => SolicitudesGridController::ESTADOS[(string) $e] ?? "Estado $e"], self::ESTADOS)];
    }

    /**
     * Tarjetas de una columna del tablero (cada solicitud cae en una sola, según sus productos y el último correo a proveedores).
     *
     * @return array{total: int, items: array<int, array<string, mixed>>}
     */
    public function columna(object $usuario, string $clave, ?string $q, ?int $estado, int $skip = 0): array
    {
        if (! isset(self::COLUMNAS[$clave])) {
            throw new InvalidArgumentException('Columna inválida.');
        }
        $estados = $estado && in_array($estado, self::ESTADOS, true) ? [$estado] : self::ESTADOS;
        $where = 't.status IN ('.implode(',', $estados).') AND t.date_creation >= DATE_SUB(CURDATE(), INTERVAL '.self::VENTANA_MESES.' MONTH)';
        $b = [];
        if (! $usuario->isAdmin()) {
            $where .= ' AND t.'.$this->actividades->campoUsuario().' = ?';
            $b[] = $usuario->getKey();
        }
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $where .= ' AND (t.ref LIKE ? OR s.nom LIKE ? OR t.asunto LIKE ?)';
            array_push($b, $like, $like, $like);
        }
        $cond = match ($clave) {
            'sinproductos' => 'x.productos = 0 AND x.correos = 0',
            'porpedir' => 'x.productos > 0 AND x.correos = 0',
            'esperando' => 'x.correos > 0 AND DATEDIFF(CURDATE(), DATE(x.ultimo_envio)) <= '.self::DIAS_ESPERA,
            default => 'x.correos > 0 AND DATEDIFF(CURDATE(), DATE(x.ultimo_envio)) > '.self::DIAS_ESPERA,
        };
        $meses = self::VENTANA_MESES;
        // Los conteos y el último seguimiento se agrupan una sola vez (sin subconsultas por fila: esas tablas no tienen índice por solicitud).
        $inner = "SELECT t.rowid, t.ref, s.nom cliente, t.asunto, t.status, t.date_creation, t.fk_soc,
                DATEDIFF(CURDATE(), DATE(t.date_creation)) dias, CONCAT_WS(' ', u.firstname, u.lastname) creador,
                COALESCE(l.n, 0) productos, COALESCE(c.n, 0) correos, c.ultimo ultimo_envio, om.mensaje ultimo_mensaje, ob.ult ultimo_fecha
            FROM {$this->p}solicitudes_solicitud t LEFT JOIN {$this->p}societe s ON s.rowid = t.fk_soc LEFT JOIN {$this->p}user u ON u.rowid = t.fk_user_creat
            LEFT JOIN (SELECT fk_solicitud, COUNT(*) n FROM {$this->p}solicitudes_solicitudline WHERE fk_cotizacion IS NULL GROUP BY fk_solicitud) l ON l.fk_solicitud = t.rowid
            LEFT JOIN (SELECT e.fk_solicitud, COUNT(*) n, MAX(d.date_creation) ultimo FROM {$this->p}cotiext_destinatarios d JOIN {$this->p}cotiext_exterior e ON e.rowid = d.fk_cotizacion_exterior WHERE e.date_creation >= DATE_SUB(CURDATE(), INTERVAL {$meses} MONTH) GROUP BY e.fk_solicitud) c ON c.fk_solicitud = t.rowid
            LEFT JOIN (SELECT fk_solicitud f, MAX(rowid) rid, MAX(tms) ult FROM {$this->p}observaciones_solicitudes GROUP BY fk_solicitud) ob ON ob.f = t.rowid
            LEFT JOIN {$this->p}observaciones_solicitudes om ON om.rowid = ob.rid
            WHERE $where";
        $total = (int) (DB::selectOne("SELECT COUNT(*) n FROM ($inner) x WHERE $cond", $b)->n ?? 0);
        $orden = in_array($clave, ['sinrespuesta', 'esperando'], true) ? 'x.ultimo_envio ASC' : 'x.dias DESC';
        $filas = DB::select("SELECT x.* FROM ($inner) x WHERE $cond ORDER BY $orden, x.rowid DESC LIMIT ".self::POR_COLUMNA.' OFFSET '.max(0, $skip), $b);

        return ['total' => $total, 'items' => array_map(function ($o) {
            $texto = ActividadesService::decodificar($o->ultimo_mensaje);

            return [
                'id' => (int) $o->rowid,
                'ref' => $o->ref,
                'cliente' => $o->cliente,
                'asunto' => self::texto($o->asunto),
                'estado' => (int) $o->status,
                'estadoNombre' => SolicitudesGridController::ESTADOS[(string) $o->status] ?? 'Estado '.$o->status,
                'dias' => max(0, (int) $o->dias),
                'productos' => (int) $o->productos,
                'correos' => (int) $o->correos,
                'ultimoEnvio' => $o->ultimo_envio ? substr((string) $o->ultimo_envio, 0, 10) : null,
                'diasEnvio' => $o->ultimo_envio ? max(0, (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($o->ultimo_envio)->startOfDay(), true)) : null,
                'creador' => $o->creador,
                'ultimoSeguimiento' => $texto !== '' ? ['texto' => mb_strimwidth($texto, 0, 140, '…'), 'fecha' => $o->ultimo_fecha ? substr((string) $o->ultimo_fecha, 0, 10) : null] : null,
            ];
        }, $filas)];
    }

    // ------------------------------------------------------------ detalle de la solicitud

    /**
     * Solicitud con sus productos y lo que ya se envió a proveedores.
     *
     * @return array<string, mixed>
     */
    public function detalle(int $id): array
    {
        $sol = $this->ficha->solicitud($id);
        if (! $sol) {
            throw new InvalidArgumentException('Solicitud no encontrada.');
        }
        $lineas = DB::select("SELECT l.rowid, l.fk_product, pr.ref producto_ref, pr.label producto_label, l.description, l.model, l.qty
            FROM {$this->p}solicitudes_solicitudline l LEFT JOIN {$this->p}product pr ON pr.rowid = l.fk_product
            WHERE l.fk_solicitud = ? AND l.fk_cotizacion IS NULL ORDER BY l.rang, l.rowid", [$id]);
        $enviados = DB::select("SELECT d.rowid, d.email, d.asunto, d.date_creation fecha, d.fk_soc, s.nom proveedor
            FROM {$this->p}cotiext_destinatarios d JOIN {$this->p}cotiext_exterior x ON x.rowid = d.fk_cotizacion_exterior
            LEFT JOIN {$this->p}societe s ON s.rowid = d.fk_soc
            WHERE x.fk_solicitud = ? ORDER BY d.rowid DESC LIMIT 60", [$id]);

        return [
            'id' => (int) $sol->rowid,
            'ref' => $sol->ref,
            'fk_soc' => (int) $sol->fk_soc,
            'cliente' => $sol->cliente,
            'asunto' => self::texto($sol->asunto),
            'estado' => (int) $sol->status,
            'productos' => array_map(fn ($l) => [
                'id' => (int) $l->rowid,
                'fk_product' => $l->fk_product ? (int) $l->fk_product : null,
                'ref' => $l->producto_ref,
                'descripcion' => trim((string) ($l->description !== null && $l->description !== '' ? $l->description : $l->producto_label)),
                'modelo' => $l->model,
                'cantidad' => (float) $l->qty,
            ], $lineas),
            'enviados' => array_map(fn ($e) => ['email' => $e->email, 'asunto' => $e->asunto, 'fecha' => substr((string) $e->fecha, 0, 10), 'fk_soc' => $e->fk_soc ? (int) $e->fk_soc : null, 'proveedor' => $e->proveedor], $enviados),
        ];
    }

    // ------------------------------------------------------------ proveedores

    /**
     * Proveedores que ya vendieron alguno de los productos (órdenes de compra, o marca del producto), de más a menos compras.
     * Es la lista que el asistente propone y resalta.
     *
     * @param  array<int, int>  $productos  fk_product
     * @return array<int, array<string, mixed>>
     */
    public function sugeridos(array $productos): array
    {
        $productos = array_values(array_unique(array_filter(array_map('intval', $productos))));
        $mapa = $this->comprasPorProveedor($productos);
        $out = [];
        foreach ($mapa as $v) {
            $out[] = $this->fichaProveedor($v);
        }
        usort($out, fn ($a, $b) => [$b['compras']['ordenes'], $b['compras']['productos']] <=> [$a['compras']['ordenes'], $a['compras']['productos']]);

        return array_slice($out, 0, 12);
    }

    /** Búsqueda de proveedores por nombre, NIT o correo; trae si ya compramos alguno de los productos con cada uno. */
    public function buscar(string $q, array $productos): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $rows = DB::select("SELECT s.rowid, s.nom, s.email, s.tva_intra nit, s.town, s.fk_pays, p.label pais
            FROM {$this->p}societe s LEFT JOIN {$this->p}c_country p ON p.rowid = s.fk_pays
            WHERE s.fournisseur = 1 AND s.status = 1 AND (s.nom LIKE ? OR s.name_alias LIKE ? OR s.tva_intra LIKE ? OR s.email LIKE ?)
            ORDER BY s.nom LIMIT 12", [$like, $like, $like, $like]);
        $compras = $this->comprasPorProveedor(array_values(array_unique(array_filter(array_map('intval', $productos)))));
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->fichaProveedor($compras[(int) $r->rowid] ?? ['id' => (int) $r->rowid, 'ordenes' => 0, 'productos' => [], 'ultima' => null, 'origen' => []]);
        }

        return $out;
    }

    /** Ficha completa de un proveedor: contactos con correo y correos usados antes en solicitudes. */
    public function proveedor(int $id, array $productos): ?array
    {
        $compras = $this->comprasPorProveedor(array_values(array_unique(array_filter(array_map('intval', $productos)))));

        return $this->fichaProveedor($compras[$id] ?? ['id' => $id, 'ordenes' => 0, 'productos' => [], 'ultima' => null, 'origen' => []]);
    }

    /**
     * @param  array{id: int, ordenes: int, productos: array, ultima: ?string, origen: array}  $v
     * @return array<string, mixed>|null
     */
    private function fichaProveedor(array $v): ?array
    {
        $s = DB::selectOne("SELECT s.rowid, s.nom, s.email, s.tva_intra nit, s.town, s.fk_pays, s.default_lang, p.label pais
            FROM {$this->p}societe s LEFT JOIN {$this->p}c_country p ON p.rowid = s.fk_pays WHERE s.rowid = ?", [$v['id']]);
        if (! $s) {
            return null;
        }
        $correos = [];
        $agregar = function (?string $email, string $nombre, string $origen) use (&$correos) {
            foreach (preg_split('/[;,\s]+/', (string) $email) as $e) {
                $e = mb_strtolower(trim($e));
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && ! isset($correos[$e])) {
                    $correos[$e] = ['email' => $e, 'nombre' => $nombre, 'origen' => $origen];
                }
            }
        };
        $agregar($s->email, 'Correo general', 'tercero');
        foreach (DB::select("SELECT TRIM(CONCAT_WS(' ', firstname, lastname)) n, poste, email FROM {$this->p}socpeople WHERE fk_soc = ? AND statut = 1 AND email IS NOT NULL AND email <> '' ORDER BY rowid DESC LIMIT 20", [$s->rowid]) as $c) {
            $agregar($c->email, trim($c->n.($c->poste ? " · {$c->poste}" : '')), 'contacto');
        }
        // Correos a los que ya se les pidió cotización en el sistema anterior con este proveedor.
        foreach (DB::select("SELECT d.email, MAX(d.date_creation) f FROM {$this->p}cotiext_destinatarios d WHERE d.fk_soc = ? GROUP BY d.email ORDER BY f DESC LIMIT 10", [$s->rowid]) as $d) {
            $agregar($d->email, 'Ya se le escribió', 'envio');
        }

        return [
            'id' => (int) $s->rowid,
            'nombre' => $s->nom,
            'nit' => $s->nit,
            'ciudad' => $s->town,
            'pais' => $s->pais,
            'exterior' => $s->fk_pays && (int) $s->fk_pays !== self::PAIS_LOCAL,
            'correos' => array_values($correos),
            'compras' => ['ordenes' => $v['ordenes'], 'productos' => count($v['productos']), 'detalle' => array_values($v['productos']), 'ultima' => $v['ultima'], 'origen' => $v['origen']],
        ];
    }

    /**
     * Por proveedor (id de societe): cuántas órdenes de compra y cuáles de los productos pedidos ya se le compraron.
     * El proveedor de las órdenes viene escrito como texto: se empareja con el tercero por nombre normalizado.
     *
     * @param  array<int, int>  $productos
     * @return array<int, array{id: int, ordenes: int, productos: array<int, array>, ultima: ?string, origen: array<int, string>}>
     */
    private function comprasPorProveedor(array $productos): array
    {
        if (! $productos) {
            return [];
        }
        $in = implode(',', array_fill(0, count($productos), '?'));
        $out = [];
        $ref = function (int $id) use (&$out) {
            return $out[$id] ??= ['id' => $id, 'ordenes' => 0, 'productos' => [], 'ultima' => null, 'origen' => []];
        };

        // 1) Órdenes de compra: producto → línea → recompra → olimpiada (con el proveedor escrito).
        $compras = DB::select("SELECT l.fk_product, pr.ref, pr.label, o.proveedor, o.fk_orden, o.fecha_orden
            FROM {$this->p}informessolu_olimpiadas_recompras r
            JOIN {$this->p}solicitudes_solicitudline l ON l.rowid = r.fk_solicitudline
            JOIN {$this->p}informessolu_olimpiadas o ON o.rowid = r.fk_olimpiada
            LEFT JOIN {$this->p}product pr ON pr.rowid = l.fk_product
            WHERE l.fk_product IN ($in) AND o.proveedor IS NOT NULL AND o.proveedor <> ''", $productos);
        $ids = $this->proveedoresPorNombre(array_unique(array_map(fn ($c) => (string) $c->proveedor, $compras)));
        $ordenes = [];
        foreach ($compras as $c) {
            $sid = $ids[self::clave((string) $c->proveedor)] ?? null;
            if (! $sid) {
                continue;
            }
            $v = $ref($sid);
            $ordenes[$sid][(int) $c->fk_orden] = true;
            $v['productos'][(int) $c->fk_product] = ['id' => (int) $c->fk_product, 'ref' => $c->ref, 'label' => $c->label];
            $f = $c->fecha_orden ? substr((string) $c->fecha_orden, 0, 10) : null;
            $v['ultima'] = $f && (! $v['ultima'] || $f > $v['ultima']) ? $f : $v['ultima'];
            $v['origen']['orden'] = 'Orden de compra';
            $out[$sid] = $v;
        }
        foreach ($ordenes as $sid => $os) {
            $out[$sid]['ordenes'] = count($os);
        }

        // 2) Proveedor habitual del producto por su marca.
        $marcas = DB::select("SELECT e.fk_product, pr.ref, pr.label, m.proveedor
            FROM {$this->p}product_extrafields_provider e JOIN {$this->p}c_marca_proveedor m ON m.rowid = e.fk_marca_proveedor
            LEFT JOIN {$this->p}product pr ON pr.rowid = e.fk_product
            WHERE e.fk_product IN ($in) AND m.proveedor > 0 AND m.active = 1", $productos);
        foreach ($marcas as $m) {
            $v = $ref((int) $m->proveedor);
            $v['productos'][(int) $m->fk_product] ??= ['id' => (int) $m->fk_product, 'ref' => $m->ref, 'label' => $m->label];
            $v['origen']['marca'] = 'Proveedor de la marca';
            $out[(int) $m->proveedor] = $v;
        }

        return $out;
    }

    /** @param  array<int, string>  $nombres  @return array<string, int> nombre normalizado → id de proveedor */
    private function proveedoresPorNombre(array $nombres): array
    {
        $claves = array_values(array_unique(array_filter(array_map([self::class, 'clave'], $nombres))));
        if (! $claves) {
            return [];
        }
        $res = [];
        foreach (array_chunk($nombres, 200) as $trozo) {
            $in = implode(',', array_fill(0, count($trozo), '?'));
            foreach (DB::select("SELECT rowid, nom FROM {$this->p}societe WHERE fournisseur = 1 AND nom IN ($in)", array_values($trozo)) as $s) {
                $res[self::clave((string) $s->nom)] ??= (int) $s->rowid;
            }
        }

        return $res;
    }

    private static function clave(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(\Illuminate\Support\Str::ascii($s))));
    }

    private static function texto(?string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    // ------------------------------------------------------------ envío

    /**
     * Envía los correos y deja el registro. `$envios`: cada uno con proveedor (id o null para uno nuevo, y su nombre), correos,
     * asunto y cuerpo HTML. Un correo que falla no frena a los demás: se devuelve el resultado de cada uno.
     *
     * @param  array<int, array{proveedor_id: ?int, nombre: string, correos: array<int, string>, asunto: string, cuerpo: string}>  $envios
     * @return array{resultados: array<int, array<string, mixed>>, estado: ?int}
     */
    public function enviar(object $usuario, object $sol, array $envios): array
    {
        $resultados = [];
        $exterior = false;
        $enviados = 0;
        $cabecera = null;

        foreach ($envios as $i => $e) {
            $correos = array_values(array_unique(array_filter(array_map(fn ($c) => mb_strtolower(trim((string) $c)), $e['correos'] ?? []), fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL))));
            $r = ['nombre' => $e['nombre'], 'ok' => false, 'error' => null];
            if (! $correos) {
                $r['error'] = 'Sin correos válidos.';
            } else {
                // El remitente se valida contra un tercero existente: se usa el cliente de la solicitud.
                $envio = $this->correo->enviarMensajePedido((int) $sol->fk_soc, 0, false, implode(';', $correos), (string) $e['asunto'], (string) $e['cuerpo']);
                if (! $envio['ok']) {
                    $r['error'] = $envio['error'] ?? 'No se pudo enviar.';
                } else {
                    $cabecera ??= $this->cabecera($usuario, $sol);
                    $this->registrar($usuario, $cabecera, $e, $correos);
                    $r['ok'] = true;
                    $r['correos'] = $correos;
                    $enviados++;
                    $exterior = $exterior || (! empty($e['proveedor_id']) && (bool) $this->esExterior((int) $e['proveedor_id']));
                }
            }
            $resultados[$i] = $r;
        }

        $estado = null;
        if ($enviados > 0) {
            DB::table('cotiext_exterior')->where('rowid', $cabecera)->update(['envios' => DB::raw('COALESCE(envios, 0) + 1'), 'fecha' => now()]);
            $estado = $this->pasarAEstado($usuario, $sol, $exterior ? 2 : 3);
        }

        return ['resultados' => $resultados, 'estado' => $estado];
    }

    private function esExterior(int $proveedor): bool
    {
        $p = DB::table('societe')->where('rowid', $proveedor)->value('fk_pays');

        return $p && (int) $p !== self::PAIS_LOCAL;
    }

    /** Cotización exterior abierta de la solicitud (la reutiliza si ya existe), o una nueva con su referencia. */
    private function cabecera(object $usuario, object $sol): int
    {
        $abierta = DB::table('cotiext_exterior')->where('fk_solicitud', $sol->rowid)->where('status', 1)->orderByDesc('rowid')->value('rowid');
        if ($abierta) {
            return (int) $abierta;
        }
        $id = DB::table('cotiext_exterior')->insertGetId([
            'ref' => 'TMP'.uniqid(),
            'fk_soc' => $sol->fk_soc,
            'date_creation' => now(),
            'fk_user_creat' => $usuario->getKey(),
            'status' => 1,
            'fecha' => now(),
            'envios' => 0,
            'fk_solicitud' => $sol->rowid,
            'fk_user_gestion' => $usuario->getKey(),
        ]);
        // Referencia como la del sistema anterior: S + AAMM + consecutivo.
        DB::table('cotiext_exterior')->where('rowid', $id)->update(['ref' => 'S'.now()->format('ym').'-'.($id - 2)]);

        return $id;
    }

    /** @param  array<int, string>  $correos */
    private function registrar(object $usuario, int $cabecera, array $e, array $correos): void
    {
        $fila = [
            'asunto' => mb_substr((string) $e['asunto'], 0, 250),
            'html_email' => (string) $e['cuerpo'],
            'idioma' => ($e['idioma'] ?? 'es') === 'en' ? 'en_US' : 'es_ES',
            'asignar_envio' => 1,
            'rowid_c_email_templates' => -1, // como el sistema anterior cuando el correo no sale de una plantilla
            'date_creation' => now(),
            'fk_cotizacion_exterior' => $cabecera,
            'fk_soc' => ! empty($e['proveedor_id']) ? (int) $e['proveedor_id'] : null,
            'fk_user_creat' => $usuario->getKey(),
            'fk_brand' => -1,
            'archivos_adjuntos' => '',
            'envios' => 1,
        ];
        foreach ($correos as $c) {
            DB::table('cotiext_destinatarios')->insert($fila + ['email' => mb_substr($c, 0, 120)]);
        }
    }

    /** Pasa la solicitud a «en solicitud de cotización» (exterior 2, local 3) si todavía se está localizando. */
    private function pasarAEstado(object $usuario, object $sol, int $destino): ?int
    {
        $actual = (int) DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->value('status');
        if (! in_array($actual, [1, 2, 3], true) || $actual === $destino || ($actual === 2 && $destino === 3)) {
            return $actual;
        }
        DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->update(['status' => $destino, 'fk_user_modif' => $usuario->getKey()]);
        DB::table('solicitudes_historico_estados')->insert([
            'fk_solicitud' => $sol->rowid, 'fk_user' => $usuario->getKey(), 'fecha' => now(),
            'estado' => $destino === 2 ? 'Solicitud de cotización exterior' : 'En cotización local',
        ]);

        return $destino;
    }
}
