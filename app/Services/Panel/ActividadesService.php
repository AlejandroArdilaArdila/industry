<?php

namespace App\Services\Panel;

use App\Models\Configuracion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Seguimientos y recordatorios de solicitudes, cotizaciones y órdenes de compra: mismo modelo de
 * datos que el Tablero de Dolibarr (informessolu/lib/seguimiento.lib.php).
 *
 *  - Observación  -> observaciones_* del módulo, con el texto codificado (base64 + gzdeflate).
 *  - Recordatorio -> evento de agenda (actioncomm, code AC_SEGUIMIENTO_RECORDATORIO*): label = título,
 *                    datep = próximo contacto, percent 0 = pendiente / 100 = realizado,
 *                    extraparams = 'seg_obs:<id observación>', note = observación [+ SEP + actividad realizada].
 *
 * Fechas de recordatorios: hora local de Bogotá tal cual la escribe el usuario (así las guarda Dolibarr en datep).
 */
class ActividadesService
{
    public const TZ = 'America/Bogota';

    public const SEP = "\n---REALIZADO---\n";

    public const AC_CODE = 'AC_SEGUIMIENTO_RECORDATORIO';

    private const AC_LIKE = 'AC\_SEGUIMIENTO\_RECORDATORIO%';

    public const MODULOS = [
        'solicitud' => ['tabla' => 'observaciones_solicitudes', 'fk' => 'fk_solicitud', 'elemento' => 'solicitud', 'obj' => 'solicitudes_solicitud', 'nombre' => 'Solicitud', 'card' => 1, 'icono' => 'fa-inbox', 'proximo' => true],
        'cotizacion' => ['tabla' => 'observaciones_cotizaciones', 'fk' => 'fk_clientecotizacion', 'elemento' => 'clientecotizacion', 'obj' => 'cotizaciones_clientecotizacion', 'nombre' => 'Cotización', 'card' => 2, 'icono' => 'fa-file-invoice-dollar', 'proximo' => false],
        'orden' => ['tabla' => 'observaciones_ordencompra', 'fk' => 'fk_orden', 'elemento' => 'orden', 'obj' => 'ordencompra_orden', 'nombre' => 'Orden de compra', 'card' => 3, 'icono' => 'fa-shopping-cart', 'proximo' => true],
    ];

    private const ESTADOS = [
        'solicitud' => [1 => 'Activa', 2 => 'Cot. Exterior', 3 => 'Cot. Local', 4 => 'Import', 5 => 'Enviada', 6 => 'Cot. USA'],
        'cotizacion' => [1 => 'Activada', 2 => 'Enviada', 3 => 'Clonada', 4 => 'Aprobada'],
        'orden' => [1 => 'Activa', 2 => 'Pago', 3 => 'Enviada', 6 => 'Aprob. Sol.', 7 => 'Aprobada', 15 => 'Liq. Sol.', 16 => 'Liquidada', 17 => 'Desemb. Sol.', 18 => 'Desemb. Apr.', 20 => 'Pago Confirm.', 32 => 'En revisión', 33 => 'Recolección', 34 => 'Env. Programar', 35 => 'Programado', 36 => 'Reprogramando', 37 => 'Reprogramado'],
    ];

    /** Nombres completos de cada estado (como en el sistema anterior) para la lista de documentos relacionados. */
    private const ESTADOS_COMPLETOS = [
        'cotizacion' => [0 => 'Borrador', 1 => 'Activada', 2 => 'Enviada', 3 => 'Clonada', 4 => 'Aprobada', 9 => 'Desactivada'],
        'exterior' => [0 => 'Borrador', 1 => 'Activa', 2 => 'Enviada', 3 => 'Cotización recibida', 9 => 'Deshabilitada'],
        'orden' => [0 => 'Borrador', 1 => 'Activa', 2 => 'Activa (abierta para pago)', 3 => 'Enviada', 4 => 'Pagada y en seguimiento', 5 => 'Cerrada', 6 => 'Solicitud de aprobación',
            7 => 'Abierta para pago', 9 => 'Cerrada', 10 => 'Cancelada', 15 => 'Solicitud de liquidación', 16 => 'Liquidada', 17 => 'Desembolso solicitado', 18 => 'Desembolso aprobado',
            19 => 'Pago aprobado', 20 => 'Pago confirmado', 31 => 'Rechazada', 32 => 'En revisión', 33 => 'Recolección', 34 => 'Enviada a programar', 35 => 'Programada',
            36 => 'Ajustando programación', 37 => 'Reprogramada'],
    ];

    /** Nombre completo de un estado (solicitud, cotización u orden), igual que en el sistema anterior. */
    public static function nombreEstado(string $modulo, int $status): string
    {
        return $modulo === 'solicitud'
            ? (\App\Http\Controllers\Panel\Solicitudes\SolicitudesGridController::ESTADOS[(string) $status] ?? 'Estado '.$status)
            : (self::ESTADOS_COMPLETOS[$modulo][$status] ?? 'Estado '.$status);
    }

    private string $p;

    public function __construct()
    {
        $this->p = DB::getTablePrefix();
    }

    // ---------------------------------------------------------- texto codificado

    /** Igual que seguimientoCodificar(): base64(gzdeflate(utf8_encode(texto))). */
    public static function codificar(string $texto): string
    {
        $latin = mb_convert_encoding(str_replace('"', "'", $texto), 'UTF-8', 'ISO-8859-1');

        return base64_encode(gzdeflate($latin, 9));
    }

    /** Igual que seguimientoDecodificar(); acepta también texto plano. */
    public static function decodificar(?string $texto): string
    {
        $texto = (string) $texto;
        if ($texto !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $texto)) {
            $bin = base64_decode($texto, true);
            $plano = $bin !== false ? @gzinflate($bin) : false;
            if ($plano !== false) {
                $texto = mb_convert_encoding($plano, 'ISO-8859-1', 'UTF-8');
            }
        }

        return trim(html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8'));
    }

    // ---------------------------------------------------------- documentos

    public function campoUsuario(): string
    {
        return (Configuracion::valores(['BUSCAR_POR_USUARIO_GESTIONADO'])['BUSCAR_POR_USUARIO_GESTIONADO'] ?? '0') === '1' ? 'fk_user_gestion' : 'fk_user_creat';
    }

    private function cfg(string $modulo): array
    {
        return self::MODULOS[$modulo] ?? throw new InvalidArgumentException('Módulo inválido.');
    }

    /** SQL de los documentos activos de un módulo, con columnas comunes (alias t = documento). */
    private function sqlDocumento(string $modulo): array
    {
        $p = $this->p;
        $uf = $this->campoUsuario();

        return match ($modulo) {
            'solicitud' => ["SELECT t.rowid id, t.ref, t.fk_soc, s.nom empresa, t.status, t.date_creation fecha, t.asunto titulo, NULL valor, t.{$uf} duenio
                FROM {$p}solicitudes_solicitud t LEFT JOIN {$p}societe s ON s.rowid = t.fk_soc", 't.status BETWEEN 1 AND 6'],
            'cotizacion' => ["SELECT t.rowid id, t.ref, t.fk_soc, s.nom empresa, t.status, t.date_creation fecha, t.label titulo,
                CASE WHEN COALESCE(t.tipo_moneda,0)=1 AND COALESCE(t.trm,0)>0 THEN COALESCE(t.total_bruto,0)*t.trm ELSE COALESCE(t.total_bruto,0) END valor, t.{$uf} duenio
                FROM {$p}cotizaciones_clientecotizacion t LEFT JOIN {$p}societe s ON s.rowid = t.fk_soc", 't.status IN (1,2)'],
            default => ["SELECT t.rowid id, t.ref, t.fk_soc, s.nom empresa, t.status, t.date_creation fecha, t.asunto titulo,
                CASE WHEN COALESCE(cc.tipo_moneda,0)=1 AND COALESCE(cc.trm,0)>0 THEN COALESCE(oe.total_productos, t.total_bruto, 0)*cc.trm ELSE COALESCE(oe.total_productos, t.total_bruto, 0) END valor, t.{$uf} duenio
                FROM {$p}ordencompra_orden t LEFT JOIN {$p}societe s ON s.rowid = t.fk_soc
                LEFT JOIN {$p}ordencompra_orden_extrafields oe ON oe.fk_object = t.rowid
                LEFT JOIN {$p}cotizaciones_clientecotizacion cc ON cc.rowid = t.fk_cliente_cotizacion", 't.status NOT IN (0,5,9,10,31)'],
        };
    }

    private function formatearDoc(string $modulo, object $d): array
    {
        $cfg = $this->cfg($modulo);

        return [
            'modulo' => $modulo,
            'moduloNombre' => $cfg['nombre'],
            'icono' => $cfg['icono'],
            'card' => $cfg['card'],
            'id' => (int) $d->id,
            'ref' => $d->ref,
            'fkSoc' => (int) $d->fk_soc,
            'empresa' => $d->empresa ?: 'Sin empresa',
            'estado' => self::ESTADOS[$modulo][(int) $d->status] ?? 'Estado '.$d->status,
            'fecha' => $d->fecha,
            'titulo' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', $this->textoPlano($d->titulo, 400))), 0, 120, '…'),
            'valor' => $d->valor !== null ? round((float) $d->valor) : null,
        ];
    }

    /** Documento con los datos para el encabezado del panel, validando que el usuario pueda gestionarlo. */
    public function documento(object $usuario, string $modulo, int $id): ?array
    {
        [$sql, $activo] = $this->sqlDocumento($modulo);
        $d = DB::selectOne("{$sql} WHERE t.rowid = ?", [$id]);
        if (! $d || ! $this->puede($usuario, $d)) {
            return null;
        }

        return $this->formatearDoc($modulo, $d);
    }

    /** Peso del cliente: cuántas solicitudes, cotizaciones y órdenes tiene y por cuánto (cotizado y en órdenes). */
    public function resumenCliente(int $fkSoc): ?array
    {
        if ($fkSoc <= 0) {
            return null;
        }
        $dash = app(PanelDashboardService::class);
        $uf = $this->campoUsuario();
        $cot = $dash->definicionEtapa('cotizacion', $uf);
        $ord = $dash->definicionEtapa('orden', $uf);
        $p = $this->p;

        $sol = (int) DB::selectOne("SELECT COUNT(*) n FROM {$p}solicitudes_solicitud t WHERE t.fk_soc = ? AND t.status > 0", [$fkSoc])->n;
        $c = DB::selectOne("SELECT COUNT(*) n, COALESCE(SUM({$cot['valor']}),0) v FROM {$cot['from']} WHERE t.fk_soc = ? AND {$cot['serie']}", [$fkSoc]);
        $o = DB::selectOne("SELECT COUNT(*) n, COALESCE(SUM({$ord['valor']}),0) v FROM {$ord['from']} WHERE t.fk_soc = ? AND {$ord['serie']}", [$fkSoc]);

        // Última fecha de cada tipo y última gestión (seguimiento o cambio en alguna solicitud del cliente).
        $ult = fn (array $d) => DB::selectOne("SELECT MAX(t.date_creation) f FROM {$d['from']} WHERE t.fk_soc = ? AND {$d['serie']}", [$fkSoc])->f;
        $sd = $dash->definicionEtapa('solicitud', $uf);
        $gest = DB::selectOne("SELECT GREATEST(COALESCE(MAX(o.tms), '1970-01-01'), COALESCE(MAX(t.tms), '1970-01-01')) f
            FROM {$p}solicitudes_solicitud t LEFT JOIN {$p}observaciones_solicitudes o ON o.fk_solicitud = t.rowid WHERE t.fk_soc = ? AND t.status > 0", [$fkSoc])->f;
        $dia = fn ($v) => $v && substr((string) $v, 0, 4) > '1970' ? substr((string) $v, 0, 10) : null;

        return [
            'solicitudes' => $sol, 'cotizaciones' => (int) $c->n, 'ordenes' => (int) $o->n,
            'montoCotizado' => round((float) $c->v), 'montoOrdenes' => round((float) $o->v),
            'ultimaSolicitud' => $dia($ult($sd)), 'ultimaCotizacion' => $dia($ult($cot)), 'ultimaOrden' => $dia($ult($ord)),
            'ultimaGestion' => $dia($gest),
        ];
    }

    /**
     * Efectividad del cliente en un periodo (por fecha de creación): de las solicitudes que hizo, cuántas llegaron a cotización, y de las
     * cotizaciones que le enviamos, cuántas terminaron en orden de compra (con su monto). Es por cohorte: cada documento cuenta si tiene el siguiente.
     *
     * @return array{desde: string, hasta: string, solicitudes: int, conCotizacion: int, cotizaciones: int, conOrden: int, montoCotizado: float, montoGanado: float, pctCotizadas: ?float, pctCierre: ?float, pctMonto: ?float, pctGlobal: ?float}
     */
    public function efectividadCliente(int $fkSoc, Carbon $desde, Carbon $hasta): array
    {
        $dash = app(PanelDashboardService::class);
        $uf = $this->campoUsuario();
        $p = $this->p;
        $d = $desde->toDateString();
        $h = $hasta->toDateString();
        $cot = $dash->definicionEtapa('cotizacion', $uf);
        $ord = $dash->definicionEtapa('orden', $uf);

        $conCot = "EXISTS (SELECT 1 FROM {$p}cotizaciones_clientecotizacion c2 WHERE c2.fk_solicitud = t.rowid AND c2.status > 0)";
        $s = DB::selectOne("SELECT COUNT(*) n, COALESCE(SUM({$conCot}),0) k FROM {$p}solicitudes_solicitud t
            WHERE t.fk_soc = ? AND t.status > 0 AND DATE(t.date_creation) BETWEEN ? AND ?", [$fkSoc, $d, $h]);

        $conOrd = "EXISTS (SELECT 1 FROM {$p}ordencompra_orden o2 WHERE o2.fk_cliente_cotizacion = t.rowid AND o2.status NOT IN (0,31))";
        $c = DB::selectOne("SELECT COUNT(*) n, COALESCE(SUM({$conOrd}),0) k, COALESCE(SUM({$cot['valor']}),0) v,
                COALESCE(SUM(CASE WHEN {$conOrd} THEN {$cot['valor']} ELSE 0 END),0) g
            FROM {$cot['from']} WHERE t.fk_soc = ? AND {$cot['serie']} AND DATE(t.date_creation) BETWEEN ? AND ?", [$fkSoc, $d, $h]);

        $pct = fn (float $a, float $b) => $b > 0 ? round($a / $b * 100, 1) : null;
        $sol = (int) $s->n;
        $cs = (int) $s->k;
        $cn = (int) $c->n;
        $co = (int) $c->k;

        return [
            'desde' => $d, 'hasta' => $h,
            'solicitudes' => $sol, 'conCotizacion' => $cs, 'cotizaciones' => $cn, 'conOrden' => $co,
            'montoCotizado' => round((float) $c->v), 'montoGanado' => round((float) $c->g),
            'pctCotizadas' => $pct($cs, $sol), 'pctCierre' => $pct($co, $cn), 'pctMonto' => $pct((float) $c->g, (float) $c->v),
            // Efectividad global del embudo: de lo que pidió, qué parte acabó vendida (solicitudes→cotización × cotización→orden).
            'pctGlobal' => $sol > 0 && $cn > 0 ? round(($cs / $sol) * ($co / $cn) * 100, 1) : ($sol > 0 ? 0.0 : null),
        ];
    }

    /**
     * Actividad mensual del cliente (últimos 24 meses, más reciente primero): documentos y monto por mes de creación.
     *
     * @return array{solicitudes: array, cotizaciones: array, ordenes: array}
     */
    public function mesesCliente(int $fkSoc, int $meses = 24): array
    {
        $dash = app(PanelDashboardService::class);
        $uf = $this->campoUsuario();
        $p = $this->p;
        $desde = Carbon::today()->startOfMonth()->subMonths($meses - 1)->toDateString();

        $porMes = fn (array $filas) => collect($filas)->keyBy('k');
        $sol = $porMes(DB::select("SELECT DATE_FORMAT(t.date_creation,'%Y-%m') k, COUNT(*) n, 0 v FROM {$p}solicitudes_solicitud t
            WHERE t.fk_soc = ? AND t.status > 0 AND DATE(t.date_creation) >= ? GROUP BY k", [$fkSoc, $desde]));
        $consulta = function (string $clave) use ($dash, $uf, $fkSoc, $desde, $porMes) {
            $d = $dash->definicionEtapa($clave, $uf);

            return $porMes(DB::select("SELECT DATE_FORMAT(t.date_creation,'%Y-%m') k, COUNT(*) n, COALESCE(SUM({$d['valor']}),0) v FROM {$d['from']}
                WHERE t.fk_soc = ? AND {$d['serie']} AND DATE(t.date_creation) >= ? GROUP BY k", [$fkSoc, $desde]));
        };
        $cot = $consulta('cotizacion');
        $ord = $consulta('orden');

        $armar = function ($datos) use ($meses) {
            $out = [];
            for ($i = 0; $i < $meses; $i++) {
                $k = Carbon::today()->startOfMonth()->subMonths($i)->format('Y-m');
                $f = $datos->get($k);
                $out[] = ['mes' => $k, 'n' => $f ? (int) $f->n : 0, 'v' => $f ? round((float) $f->v) : 0];
            }

            return $out;
        };

        // Resumen por año de todo el histórico del cliente.
        $porAnio = function (string $from, string $valor, string $where) use ($fkSoc) {
            return array_map(fn ($r) => ['anio' => (int) $r->k, 'n' => (int) $r->n, 'v' => round((float) $r->v)],
                DB::select("SELECT YEAR(t.date_creation) k, COUNT(*) n, COALESCE(SUM({$valor}),0) v FROM {$from} WHERE t.fk_soc = ? AND {$where} GROUP BY k ORDER BY k DESC", [$fkSoc]));
        };
        $dc = $dash->definicionEtapa('cotizacion', $uf);
        $do = $dash->definicionEtapa('orden', $uf);

        return [
            'solicitudes' => $armar($sol), 'cotizaciones' => $armar($cot), 'ordenes' => $armar($ord),
            'anios' => [
                'solicitudes' => $porAnio("{$p}solicitudes_solicitud t", '0', 't.status > 0'),
                'cotizaciones' => $porAnio($dc['from'], $dc['valor'], $dc['serie']),
                'ordenes' => $porAnio($do['from'], $do['valor'], $do['serie']),
            ],
        ];
    }

    /**
     * Datos del documento como los mostraba el sistema anterior, ya formateados para la pestaña del panel:
     * campos (etiqueta, valor), textos largos y una tabla de productos. Null si no se pueden leer.
     *
     * @return array{campos: array, textos: array, tabla: array{cols: array, der: array, filas: array}}|null
     */
    public function detalleDocumento(string $modulo, int $id): ?array
    {
        try {
            return match ($modulo) {
                'solicitud' => $this->detalleSolicitud($id),
                'cotizacion' => $this->detalleCotizacion($id),
                default => $this->detalleOrden($id),
            };
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Documentos ligados a uno: de una solicitud, sus cotizaciones (a proveedores y al cliente) y órdenes de compra; de una
     * cotización, su solicitud, hermanas y órdenes; de una orden, su cotización y su solicitud.
     * `modulo` queda null en los que el panel no abre (cotización a proveedores).
     *
     * @return array<int, array{titulo: string, icono: string, items: array<int, array<string, mixed>>}>
     */
    public function relacionados(string $modulo, int $id): array
    {
        $p = $this->p;
        $valorCot = 'CASE WHEN COALESCE(c.tipo_moneda,0)=1 AND COALESCE(c.trm,0)>0 THEN COALESCE(c.total_bruto,0)*c.trm ELSE COALESCE(c.total_bruto,0) END';
        $cotiz = fn (string $where, array $b) => array_map(fn ($r) => $this->itemRel('cotizacion', $r), DB::select(
            "SELECT c.rowid id, c.ref, c.status, c.date_creation fecha, c.label titulo, $valorCot valor FROM {$p}cotizaciones_clientecotizacion c WHERE $where ORDER BY c.rowid DESC LIMIT 30", $b));
        $ordenes = fn (string $where, array $b) => array_map(fn ($r) => $this->itemRel('orden', $r), DB::select(
            "SELECT o.rowid id, o.ref, o.status, o.date_creation fecha, o.asunto titulo,
                CASE WHEN COALESCE(c.tipo_moneda,0)=1 AND COALESCE(c.trm,0)>0 THEN COALESCE(oe.total_productos, o.total_bruto, 0)*c.trm ELSE COALESCE(oe.total_productos, o.total_bruto, 0) END valor
             FROM {$p}ordencompra_orden o LEFT JOIN {$p}ordencompra_orden_extrafields oe ON oe.fk_object = o.rowid
             LEFT JOIN {$p}cotizaciones_clientecotizacion c ON c.rowid = o.fk_cliente_cotizacion WHERE $where ORDER BY o.rowid DESC LIMIT 30", $b));
        $solicitud = fn (int $sid) => array_map(fn ($r) => $this->itemRel('solicitud', $r), DB::select(
            "SELECT t.rowid id, t.ref, t.status, t.date_creation fecha, t.asunto titulo, NULL valor FROM {$p}solicitudes_solicitud t WHERE t.rowid = ?", [$sid]));
        $exterior = fn (int $sid) => array_map(fn ($r) => $this->itemRel(null, $r), DB::select(
            "SELECT e.rowid id, e.ref, e.status, e.date_creation fecha, NULL titulo, NULL valor FROM {$p}cotiext_exterior e WHERE e.fk_solicitud = ? ORDER BY e.rowid DESC LIMIT 30", [$sid]));

        $grupos = [];
        $sid = null;
        if ($modulo === 'solicitud') {
            $sid = $id;
            $cot = $cotiz('c.fk_solicitud = ?', [$id]);
            $ids = array_column($cot, 'id');
            $grupos[] = ['titulo' => 'Cotizaciones a proveedores', 'icono' => 'fa-paper-plane', 'items' => $exterior($id)];
            $grupos[] = ['titulo' => 'Cotizaciones al cliente', 'icono' => 'fa-file-invoice-dollar', 'items' => $cot];
            $grupos[] = ['titulo' => 'Órdenes de compra', 'icono' => 'fa-shopping-cart', 'items' => $ids ? $ordenes('o.fk_cliente_cotizacion IN ('.implode(',', array_map('intval', $ids)).')', []) : []];
        } elseif ($modulo === 'cotizacion') {
            $sid = (int) (DB::selectOne("SELECT fk_solicitud s FROM {$p}cotizaciones_clientecotizacion WHERE rowid = ?", [$id])->s ?? 0);
            $grupos[] = ['titulo' => 'Solicitud', 'icono' => 'fa-inbox', 'items' => $sid ? $solicitud($sid) : []];
            $grupos[] = ['titulo' => 'Cotizaciones a proveedores', 'icono' => 'fa-paper-plane', 'items' => $sid ? $exterior($sid) : []];
            $grupos[] = ['titulo' => 'Otras cotizaciones de la solicitud', 'icono' => 'fa-file-invoice-dollar', 'items' => $sid ? $cotiz('c.fk_solicitud = ? AND c.rowid <> ?', [$sid, $id]) : []];
            $grupos[] = ['titulo' => 'Órdenes de compra', 'icono' => 'fa-shopping-cart', 'items' => $ordenes('o.fk_cliente_cotizacion = ?', [$id])];
        } else {
            $cid = (int) (DB::selectOne("SELECT fk_cliente_cotizacion c FROM {$p}ordencompra_orden WHERE rowid = ?", [$id])->c ?? 0);
            $sid = $cid ? (int) (DB::selectOne("SELECT fk_solicitud s FROM {$p}cotizaciones_clientecotizacion WHERE rowid = ?", [$cid])->s ?? 0) : 0;
            $grupos[] = ['titulo' => 'Solicitud', 'icono' => 'fa-inbox', 'items' => $sid ? $solicitud($sid) : []];
            $grupos[] = ['titulo' => 'Cotización al cliente', 'icono' => 'fa-file-invoice-dollar', 'items' => $cid ? $cotiz('c.rowid = ?', [$cid]) : []];
            $grupos[] = ['titulo' => 'Otras órdenes de la cotización', 'icono' => 'fa-shopping-cart', 'items' => $cid ? $ordenes('o.fk_cliente_cotizacion = ? AND o.rowid <> ?', [$cid, $id]) : []];
        }

        return array_values(array_filter($grupos, fn ($g) => $g['items']));
    }

    private function itemRel(?string $modulo, object $r): array
    {
        return [
            'modulo' => $modulo, 'id' => (int) $r->id, 'ref' => (string) $r->ref,
            'estado' => $modulo === 'solicitud'
                ? (\App\Http\Controllers\Panel\Solicitudes\SolicitudesGridController::ESTADOS[(string) $r->status] ?? 'Estado '.$r->status)
                : (self::ESTADOS_COMPLETOS[$modulo ?? 'exterior'][(int) $r->status] ?? 'Estado '.$r->status),
            'fecha' => $r->fecha ? substr((string) $r->fecha, 0, 10) : null,
            'titulo' => $r->titulo !== null ? trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $r->titulo), ENT_QUOTES, 'UTF-8'))) : null,
            'valor' => $r->valor !== null ? (float) $r->valor : null,
        ];
    }

    private function fechaTxt(?string $v, bool $hora = false): ?string
    {
        return $v && ! str_starts_with($v, '0000') ? Carbon::parse($v)->format($hora ? 'd/m/Y H:i' : 'd/m/Y') : null;
    }

    private function dineroTxt($v): ?string
    {
        return $v !== null && $v !== '' ? '$ '.number_format((float) $v, 0, ',', '.') : null;
    }

    private function textoPlano($v, int $max = 3000): string
    {
        $t = trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li)[^>]*>/i', "\n", (string) $v)), ENT_QUOTES, 'UTF-8')));

        return mb_strimwidth($t, 0, $max, '…');
    }

    private function tablaProductos(string $where, array $b): array
    {
        $p = $this->p;
        $rows = DB::select("SELECT pr.ref, l.description, l.model, l.qty, l.subprice, l.total_ht FROM {$p}solicitudes_solicitudline l LEFT JOIN {$p}product pr ON pr.rowid = l.fk_product
            WHERE {$where} ORDER BY l.rang, l.rowid LIMIT 100", $b);
        $conPrecio = collect($rows)->contains(fn ($r) => (float) $r->subprice > 0);
        $num = fn ($v) => $v !== null ? rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') : '';

        return [
            'cols' => $conPrecio ? ['Referencia', 'Descripción', 'Modelo', 'Cant.', 'Valor unit.', 'Total'] : ['Referencia', 'Descripción', 'Modelo', 'Cant.'],
            'der' => $conPrecio ? [3, 4, 5] : [3],
            'filas' => array_map(fn ($r) => array_merge([$r->ref ?: '—', $this->textoPlano($r->description, 160), $r->model, $num($r->qty)], $conPrecio ? [$this->dineroTxt($r->subprice), $this->dineroTxt($r->total_ht)] : []), $rows),
        ];
    }

    private function detalleSolicitud(int $id): ?array
    {
        $p = $this->p;
        $t = DB::selectOne("SELECT t.fecha_solicitud, t.fecha_proximo_contacto, t.ultima_respuesta, DATEDIFF(NOW(), COALESCE(t.fecha_reapertura, t.fecha_solicitud, t.date_creation)) mora, t.detalle, t.observaciones,
                TRIM(CONCAT_WS(' ', c.firstname, c.lastname)) contacto, c.email contacto_email, COALESCE(NULLIF(c.phone_mobile,''), c.phone) contacto_tel,
                TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) creador
            FROM {$p}solicitudes_solicitud t LEFT JOIN {$p}socpeople c ON c.rowid = t.contacto_id LEFT JOIN {$p}user u ON u.rowid = t.fk_user_creat WHERE t.rowid = ?", [$id]);
        if (! $t) {
            return null;
        }

        return [
            'campos' => [['Fecha de solicitud', $this->fechaTxt($t->fecha_solicitud)], ['Próximo contacto', $this->fechaTxt($t->fecha_proximo_contacto)], ['Última respuesta', $this->fechaTxt($t->ultima_respuesta)],
                ['Mora', $t->mora !== null ? max(0, (int) $t->mora).' días' : null], ['Contacto', $t->contacto], ['Correo', $t->contacto_email], ['Teléfono', $t->contacto_tel], ['Creada por', $t->creador]],
            'textos' => [['Detalle (cuerpo del correo)', $this->textoPlano($t->detalle)], ['Observaciones (uso interno)', $this->textoPlano($t->observaciones)]],
            'tabla' => $this->tablaProductos('l.fk_solicitud = ?', [$id]) + ['titulo' => 'Productos solicitados'],
        ];
    }

    private function detalleCotizacion(int $id): ?array
    {
        $p = $this->p;
        $t = DB::selectOne("SELECT t.date_creation, t.fecha_retoma, t.tiempo_entrega_pdf, t.validez_oferta, t.garantia, t.tipo_cotizacion, t.tipo_moneda, t.trm, t.total_bruto, t.fletes, t.gastos_bancarios,
                t.aplicar_iva, t.incoterms, t.observaciones, sol.ref solicitud, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) creador, DATEDIFF(NOW(), COALESCE(sol.fecha_solicitud, sol.date_creation, t.date_creation)) mora
            FROM {$p}cotizaciones_clientecotizacion t LEFT JOIN {$p}solicitudes_solicitud sol ON sol.rowid = t.fk_solicitud LEFT JOIN {$p}user u ON u.rowid = t.fk_user_creat WHERE t.rowid = ?", [$id]);
        if (! $t) {
            return null;
        }
        $moneda = (int) $t->tipo_moneda === 1 ? 'Dólares' : ((int) $t->tipo_moneda === 2 ? 'Pesos' : null);

        return [
            'campos' => [['Solicitud', $t->solicitud], ['Creada', $this->fechaTxt($t->date_creation)], ['Fecha de retoma', $this->fechaTxt($t->fecha_retoma)], ['Mora', $t->mora !== null ? max(0, (int) $t->mora).' días' : null],
                ['Moneda', $moneda], ['TRM', (float) $t->trm > 0 ? number_format((float) $t->trm, 2, ',', '.') : null], ['Total bruto', $this->dineroTxt($t->total_bruto)], ['Fletes', $this->dineroTxt($t->fletes)],
                ['Gastos bancarios', $this->dineroTxt($t->gastos_bancarios)], ['IVA', (int) $t->aplicar_iva === 1 ? 'Sí' : ((int) $t->aplicar_iva === 0 ? 'No' : null)], ['Tiempo de entrega', $t->tiempo_entrega_pdf ? $t->tiempo_entrega_pdf.' días' : null],
                ['Validez de la oferta', $t->validez_oferta ? $t->validez_oferta.' días' : null], ['Garantía', $t->garantia ? $t->garantia.' días' : null], ['Incoterms', $t->incoterms], ['Creada por', $t->creador]],
            'textos' => [['Observaciones (PDF)', $this->textoPlano($t->observaciones)]],
            'tabla' => $this->tablaProductos('l.fk_cotizacion = ?', [$id]) + ['titulo' => 'Productos cotizados'],
        ];
    }

    private function detalleOrden(int $id): ?array
    {
        $p = $this->p;
        $t = DB::selectOne("SELECT t.date_creation, t.tiempo_entrega, t.fecha_entrega_cliente, t.fecha_proximo_contacto, t.fecha_pagado, t.numero_factura, t.numero_guia, t.numero_guia_despacho, t.fechaDespacho,
                t.metodo_pago, t.motivo_de_compra, t.observaciones, t.fk_cliente_cotizacion, cc.ref cotizacion, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) creador,
                COALESCE(oe.total_productos, t.total_bruto) total
            FROM {$p}ordencompra_orden t LEFT JOIN {$p}ordencompra_orden_extrafields oe ON oe.fk_object = t.rowid LEFT JOIN {$p}cotizaciones_clientecotizacion cc ON cc.rowid = t.fk_cliente_cotizacion
            LEFT JOIN {$p}user u ON u.rowid = t.fk_user_creat WHERE t.rowid = ?", [$id]);
        if (! $t) {
            return null;
        }
        $metodos = [1 => 'Consignación local', 2 => 'Giro internacional', 3 => 'Tarjeta de crédito', 4 => 'Pago por plataforma', 11 => 'Efectivo', 12 => 'Tarjeta de débito', 13 => 'Industry Solutions'];

        return [
            'campos' => [['Cotización', $t->cotizacion], ['Fecha de la orden', $this->fechaTxt($t->date_creation)], ['Total', $this->dineroTxt($t->total)], ['Método de pago', $metodos[(int) $t->metodo_pago] ?? null],
                ['Fecha de pago', $this->fechaTxt($t->fecha_pagado)], ['Factura', $t->numero_factura], ['Tiempo de entrega', $this->fechaTxt($t->tiempo_entrega)], ['Entrega al cliente', $this->fechaTxt($t->fecha_entrega_cliente)],
                ['Próximo contacto', $this->fechaTxt($t->fecha_proximo_contacto, true)], ['Guía', $t->numero_guia], ['Guía de despacho', $t->numero_guia_despacho], ['Fecha de despacho', $this->fechaTxt($t->fechaDespacho)], ['Creada por', $t->creador]],
            'textos' => [['Motivo de compra', $this->textoPlano($t->motivo_de_compra)], ['Observaciones', $this->textoPlano($t->observaciones)]],
            'tabla' => ((int) $t->fk_cliente_cotizacion > 0 ? $this->tablaProductos('l.fk_cotizacion = ?', [(int) $t->fk_cliente_cotizacion]) : ['cols' => [], 'der' => [], 'filas' => []]) + ['titulo' => 'Productos de la cotización'],
        ];
    }

    private function puede(object $usuario, object $d): bool
    {
        return $usuario->isAdmin() || (int) $d->duenio === (int) $usuario->getKey();
    }

    /** Búsqueda de documentos activos para programar una actividad (los del usuario; el admin ve todos). */
    public function buscar(object $usuario, string $q, ?string $modulo, int $limite = 8): array
    {
        $q = trim($q);
        $out = [];
        foreach (array_keys(self::MODULOS) as $m) {
            if ($modulo && $modulo !== $m) {
                continue;
            }
            [$sql, $activo] = $this->sqlDocumento($m);
            $b = [];
            $w = $activo;
            if (! $usuario->isAdmin()) {
                $w .= ' AND t.'.$this->campoUsuario().' = ?';
                $b[] = $usuario->getKey();
            }
            if ($q !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
                $w .= ' AND (t.ref LIKE ? OR s.nom LIKE ?)';
                array_push($b, $like, $like);
            }
            foreach (DB::select("{$sql} WHERE {$w} ORDER BY t.rowid DESC LIMIT {$limite}", $b) as $d) {
                $out[] = $this->formatearDoc($m, $d);
            }
        }

        return $out;
    }

    // ---------------------------------------------------------- listados

    /**
     * Recordatorios del usuario.
     *
     * @return array{items: array, resumen: array}
     */
    public function recordatorios(object $usuario, string $estado = 'pendientes', int $limite = 200): array
    {
        $p = $this->p;
        $uid = (int) $usuario->getKey();
        $pend = $estado !== 'realizados';

        $filas = DB::select("SELECT a.id, a.label, a.datep, a.percent, a.note, a.fk_element, a.elementtype,
                COALESCE(ss.ref, cc.ref, oo.ref) ref, COALESCE(s.nom, 'Sin empresa') empresa,
                (mv.ms_evento_id IS NOT NULL OR mv.ms_tarea_id IS NOT NULL) ms, mv.teams_url
            FROM {$p}actioncomm a
            LEFT JOIN {$p}panel_ms_vinculos mv ON mv.actioncomm_id = a.id
            LEFT JOIN {$p}solicitudes_solicitud ss ON ss.rowid = a.fk_element AND a.elementtype = 'solicitud'
            LEFT JOIN {$p}cotizaciones_clientecotizacion cc ON cc.rowid = a.fk_element AND a.elementtype = 'clientecotizacion'
            LEFT JOIN {$p}ordencompra_orden oo ON oo.rowid = a.fk_element AND a.elementtype = 'orden'
            LEFT JOIN {$p}societe s ON s.rowid = COALESCE(ss.fk_soc, cc.fk_soc, oo.fk_soc)
            WHERE a.code LIKE ? AND a.elementtype IN ('solicitud','clientecotizacion','orden')
              AND (a.fk_user_author = ? OR a.fk_user_action = ?) AND ".($pend ? 'a.percent < 100' : 'a.percent >= 100').'
            ORDER BY '.($pend ? 'a.datep ASC' : 'a.tms DESC')." LIMIT {$limite}", [self::AC_LIKE, $uid, $uid]);

        $ahora = Carbon::now(self::TZ)->startOfMinute();
        $hoy = $ahora->copy()->startOfDay();
        $porElemento = [];
        foreach (self::MODULOS as $k => $m) {
            $porElemento[$m['elemento']] = $k;
        }

        $items = array_map(function ($f) use ($ahora, $hoy, $porElemento, $pend) {
            $cuando = Carbon::parse($f->datep, self::TZ);
            $partes = explode(self::SEP, (string) $f->note, 2);
            $modulo = $porElemento[$f->elementtype];

            return [
                'id' => (int) $f->id,
                'modulo' => $modulo,
                'moduloNombre' => self::MODULOS[$modulo]['nombre'],
                'card' => self::MODULOS[$modulo]['card'],
                'icono' => self::MODULOS[$modulo]['icono'],
                'fkRecord' => (int) $f->fk_element,
                'ref' => $f->ref,
                'empresa' => $f->empresa,
                'titulo' => $f->label,
                'cuando' => $cuando->format('Y-m-d H:i'),
                'grupo' => ! $pend ? 'realizado' : ($cuando->lt($hoy) ? 'atrasado' : ($cuando->lt($hoy->copy()->addDay()) ? 'hoy' : 'proximo')),
                'horaVencida' => $pend && $cuando->lt($ahora),
                'observacion' => trim($partes[0]),
                'actividad' => trim($partes[1] ?? ''),
                'ms' => (bool) $f->ms,
                'teams' => $f->teams_url ?: null,
            ];
        }, $filas);

        $r = DB::selectOne("SELECT
                COALESCE(SUM(a.percent < 100 AND DATE(a.datep) < ?),0) atrasados,
                COALESCE(SUM(a.percent < 100 AND DATE(a.datep) = ?),0) hoy,
                COALESCE(SUM(a.percent < 100 AND DATE(a.datep) > ?),0) proximos,
                COALESCE(SUM(a.percent >= 100),0) realizados
            FROM {$p}actioncomm a WHERE a.code LIKE ? AND a.elementtype IN ('solicitud','clientecotizacion','orden')
              AND (a.fk_user_author = ? OR a.fk_user_action = ?)", [$hoy->toDateString(), $hoy->toDateString(), $hoy->toDateString(), self::AC_LIKE, $uid, $uid]);

        return ['items' => $items, 'resumen' => array_map('intval', (array) $r)];
    }

    /** Observaciones que registró el usuario (más recientes primero). */
    public function misSeguimientos(object $usuario, int $limite = 60): array
    {
        $p = $this->p;
        $uid = (int) $usuario->getKey();
        $out = [];

        foreach (self::MODULOS as $modulo => $cfg) {
            $rows = DB::select("SELECT o.rowid id, o.mensaje, o.tms, o.{$cfg['fk']} fk, t.ref, COALESCE(s.nom, 'Sin empresa') empresa
                FROM {$p}{$cfg['tabla']} o
                INNER JOIN {$p}{$cfg['obj']} t ON t.rowid = o.{$cfg['fk']}
                LEFT JOIN {$p}societe s ON s.rowid = t.fk_soc
                WHERE o.fk_user_creat = ? ORDER BY o.rowid DESC LIMIT {$limite}", [$uid]);
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (int) $r->id, 'modulo' => $modulo, 'moduloNombre' => $cfg['nombre'], 'card' => $cfg['card'], 'icono' => $cfg['icono'],
                    'fkRecord' => (int) $r->fk, 'ref' => $r->ref, 'empresa' => $r->empresa,
                    'texto' => mb_strimwidth(self::decodificar($r->mensaje), 0, 220, '…'), 'fecha' => $r->tms,
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));

        return array_slice($out, 0, $limite);
    }

    /** Línea de tiempo de un documento: observaciones y recordatorios, más recientes primero. */
    public function historial(string $modulo, int $id, int $limite = 60): array
    {
        $p = $this->p;
        $cfg = $this->cfg($modulo);
        $items = [];
        $eventos = [];

        foreach (DB::select("SELECT a.id, a.label, a.datep, a.percent, a.note, a.extraparams, a.tms, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) autor
            FROM {$p}actioncomm a LEFT JOIN {$p}user u ON u.rowid = a.fk_user_author
            WHERE a.code LIKE ? AND a.elementtype = ? AND a.fk_element = ?", [self::AC_LIKE, $cfg['elemento'], $id]) as $a) {
            $partes = explode(self::SEP, (string) $a->note, 2);
            if (str_starts_with((string) $a->extraparams, 'seg_obs:')) {
                $eventos[(int) substr($a->extraparams, 8)] = $a->id;
            }
            $items[] = [
                'tipo' => 'recordatorio', 'eventoId' => (int) $a->id, 'fecha' => $a->tms, 'cuando' => Carbon::parse($a->datep)->format('Y-m-d H:i'),
                'autor' => $a->autor, 'titulo' => $a->label, 'realizado' => (int) $a->percent >= 100,
                'texto' => trim($partes[0]), 'actividad' => trim($partes[1] ?? ''), 'obsId' => str_starts_with((string) $a->extraparams, 'seg_obs:') ? (int) substr($a->extraparams, 8) : null,
            ];
        }

        $enlazadas = array_filter(array_column($items, 'obsId'));
        foreach (DB::select("SELECT o.rowid id, o.mensaje, o.tms, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) autor
            FROM {$p}{$cfg['tabla']} o LEFT JOIN {$p}user u ON u.rowid = o.fk_user_creat
            WHERE o.{$cfg['fk']} = ? ORDER BY o.rowid DESC LIMIT {$limite}", [$id]) as $o) {
            if (in_array((int) $o->id, $enlazadas, true)) {
                continue; // su texto ya va en el recordatorio que la acompaña
            }
            $items[] = ['tipo' => 'observacion', 'fecha' => $o->tms, 'autor' => $o->autor, 'texto' => self::decodificar($o->mensaje)];
        }

        usort($items, fn ($a, $b) => strcmp((string) $b['fecha'], (string) $a['fecha']));

        return array_slice($items, 0, $limite);
    }

    // ---------------------------------------------------------- escritura

    /**
     * Registra una observación y, si viene fecha, su recordatorio.
     *
     * @return array{observacion: int, evento: ?int}
     */
    public function crear(object $usuario, string $modulo, int $id, string $observacion, ?string $fecha, ?string $hora, ?string $titulo): array
    {
        $cfg = $this->cfg($modulo);
        $doc = $this->documento($usuario, $modulo, $id);
        if (! $doc) {
            throw new InvalidArgumentException('No puedes registrar seguimientos en este documento.');
        }
        $uid = (int) $usuario->getKey();

        return DB::transaction(function () use ($cfg, $doc, $uid, $id, $observacion, $fecha, $hora, $titulo) {
            $obsId = DB::table($cfg['tabla'])->insertGetId([$cfg['fk'] => $id, 'fk_user_creat' => $uid, 'mensaje' => self::codificar($observacion)]);

            $eventoId = null;
            if ($fecha) {
                $datep = Carbon::createFromFormat('Y-m-d H:i', $fecha.' '.($hora ?: '08:00'), self::TZ)->format('Y-m-d H:i:s');
                $eventoId = DB::table('actioncomm')->insertGetId([
                    'ref' => '', 'entity' => 1, 'datep' => $datep, 'datep2' => $datep, 'fk_action' => 50, 'code' => self::AC_CODE.'_TABLERO',
                    'datec' => now(), 'fk_user_author' => $uid, 'fk_user_action' => $uid, 'fk_parent' => 0, 'transparency' => 1, 'priority' => 0,
                    'visibility' => 'default', 'fulldayevent' => 0, 'percent' => 0, 'durationp' => 0, 'label' => mb_substr((string) $titulo, 0, 250),
                    'note' => $observacion, 'fk_soc' => $doc['fkSoc'] ?: null, 'fk_element' => $id, 'elementtype' => $cfg['elemento'],
                    'extraparams' => 'seg_obs:'.$obsId, 'event_paid' => 0, 'status' => 0,
                ]);
                DB::table('actioncomm')->where('id', $eventoId)->update(['ref' => (string) $eventoId]);
                DB::table('actioncomm_resources')->insert(['fk_actioncomm' => $eventoId, 'element_type' => 'user', 'fk_element' => $uid, 'answer_status' => '0', 'mandatory' => 0, 'transparency' => 1]);

                if ($cfg['proximo']) {
                    DB::table($cfg['obj'])->where('rowid', $id)->update(['fecha_proximo_contacto' => $datep]);
                }
            }

            return ['observacion' => $obsId, 'evento' => $eventoId];
        });
    }

    /** Marca un recordatorio como realizado (con la actividad hecha) o lo devuelve a pendiente. */
    public function cambiarEstado(object $usuario, int $eventoId, string $estado, string $actividad = ''): void
    {
        $uid = (int) $usuario->getKey();
        $ev = DB::selectOne("SELECT id, fk_element, elementtype, label, note, fk_user_author, fk_user_action FROM {$this->p}actioncomm WHERE id = ? AND code LIKE ?", [$eventoId, self::AC_LIKE]);
        if (! $ev || ! ($usuario->isAdmin() || (int) $ev->fk_user_author === $uid || (int) $ev->fk_user_action === $uid)) {
            throw new InvalidArgumentException('Recordatorio no encontrado.');
        }
        $nota = explode(self::SEP, (string) $ev->note, 2)[0];

        DB::transaction(function () use ($ev, $uid, $estado, $actividad, $nota) {
            if ($estado === 'REALIZADO') {
                DB::table('actioncomm')->where('id', $ev->id)->update(['percent' => 100, 'fk_user_done' => $uid, 'fk_user_mod' => $uid, 'note' => $nota.self::SEP.$actividad]);
                foreach (self::MODULOS as $cfg) {
                    if ($cfg['elemento'] === $ev->elementtype) {
                        DB::table($cfg['tabla'])->insert([$cfg['fk'] => $ev->fk_element, 'fk_user_creat' => $uid, 'mensaje' => self::codificar("Recordatorio realizado — {$ev->label}: {$actividad}")]);
                    }
                }
            } else {
                DB::table('actioncomm')->where('id', $ev->id)->update(['percent' => 0, 'fk_user_done' => null, 'fk_user_mod' => $uid, 'note' => $nota]);
            }
        });
    }

    /** Mueve un recordatorio pendiente a otra fecha/hora. @return string nueva fecha-hora (Y-m-d H:i:s) */
    public function posponer(object $usuario, int $eventoId, string $fecha, ?string $hora): string
    {
        $uid = (int) $usuario->getKey();
        $ev = DB::selectOne("SELECT id, fk_element, elementtype, datep, fk_user_author, fk_user_action FROM {$this->p}actioncomm WHERE id = ? AND code LIKE ?", [$eventoId, self::AC_LIKE]);
        if (! $ev || ! ($usuario->isAdmin() || (int) $ev->fk_user_author === $uid || (int) $ev->fk_user_action === $uid)) {
            throw new InvalidArgumentException('Recordatorio no encontrado.');
        }
        $datep = Carbon::createFromFormat('Y-m-d H:i', $fecha.' '.($hora ?: substr((string) $ev->datep, 11, 5)), self::TZ)->format('Y-m-d H:i:s');
        DB::transaction(function () use ($eventoId, $ev, $datep, $uid) {
            DB::table('actioncomm')->where('id', $eventoId)->update(['datep' => $datep, 'datep2' => $datep, 'fk_user_mod' => $uid]);
            foreach (self::MODULOS as $cfg) {
                if ($cfg['elemento'] === $ev->elementtype && $cfg['proximo']) {
                    DB::table($cfg['obj'])->where('rowid', $ev->fk_element)->update(['fecha_proximo_contacto' => $datep]);
                }
            }
        });

        return $datep;
    }
}
