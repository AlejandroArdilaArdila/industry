<?php

namespace App\Services\Panel;

use App\Services\Ia\IaConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Línea de proceso comercial de los negociadores: por etapa, el total de documentos y la fecha más antigua (o la última),
 * contando solo desde el año de INFORMESSOLU_ANIO_DESDE. Cada etapa se configura (Configuración → Panel): de qué documentos
 * sale (fuente), qué estados cuentan y a cuántos días pasa de verde a amarillo y a rojo.
 */
class ProcesoComercialService
{
    public const CONST_PROCESO = 'PANEL_DASH_PROCESO';

    /** Fuentes de datos. 'estado' = columna que guarda el estado; los estados de las guías son texto, los demás numéricos. */
    public const FUENTES = [
        'solicitud' => 'Solicitudes',
        'cotizacion' => 'Cotizaciones',
        'orden' => 'Órdenes de compra',
        'guia' => 'Guías de entrega',
    ];

    /** Estados numéricos de cada documento (clases de Dolibarr solicitudes, cotizaciones y ordencompra). */
    public const ESTADOS = [
        'solicitud' => [0 => 'Borrador', 1 => 'Activa', 2 => 'En solicitud de cotización exterior', 3 => 'En solicitud de cotización local', 4 => 'Importación', 5 => 'Cotización enviada',
            6 => 'En solicitud de cotización USA', 9 => 'Cancelada', 10 => 'Orden de compra abierta', 11 => 'Orden enviada', 12 => 'Orden en aprobación', 13 => 'Orden en liquidación',
            14 => 'Orden liquidada', 15 => 'Orden aprobada', 16 => 'Orden pagada y en seguimiento', 17 => 'Desembolso solicitado', 18 => 'Desembolso realizado',
            19 => 'Devolución registrada', 20 => 'Orden cerrada', 21 => 'Orden cancelada', 22 => 'En cotización exterior', 23 => 'En cotización local', 24 => 'En cotización USA'],
        'cotizacion' => [0 => 'Borrador', 1 => 'Validada', 2 => 'Enviada', 3 => 'Clonada', 4 => 'Aprobada', 9 => 'Cancelada'],
        'orden' => [0 => 'Borrador', 1 => 'Validada', 2 => 'Abierta para pago', 3 => 'Enviada', 4 => 'Pagada y en seguimiento', 5 => 'Cerrada', 6 => 'Solicitud aprobada', 7 => 'Orden aprobada',
            9 => 'Cancelada', 10 => 'Anulada por administrador', 15 => 'Solicitud de liquidación', 16 => 'Liquidada', 17 => 'Solicitud de desembolso', 18 => 'Desembolso aprobado',
            19 => 'Pago aprobado', 20 => 'Pago confirmado', 31 => 'Rechazada', 32 => 'En revisión', 33 => 'Con datos de recolección', 34 => 'Enviado a programar', 35 => 'Programado',
            36 => 'Reprogramando', 37 => 'Reprogramado'],
    ];

    /** Estados que cuentan como «activos» en el tablero (mismas reglas de PanelDashboardService::definicionEtapa). */
    private const ACTIVOS = [
        'solicitud' => [1, 2, 3, 4, 5, 6],
        'cotizacion' => [1, 2],
        'orden' => [1, 2, 3, 4, 6, 7, 15, 16, 17, 18, 19, 20, 32, 33, 34, 35, 36, 37],
    ];

    /** Etapas fijas (el orden de la línea no cambia). Las de fuente null quedan «sin definir» hasta que se configuren. */
    private const ETAPAS = [
        'solicitud' => ['Solicitud', 'solicitud', null, 'antigua'],
        'localizacion' => ['Localización', null, null, 'antigua'],
        'cotizacion' => ['Cotización', 'cotizacion', null, 'antigua'],
        'negociacion' => ['Negociación', null, null, 'antigua'],
        'orden' => ['Orden de compra', 'orden', null, 'antigua'],
        'produccion' => ['Producción', null, null, 'antigua'],
        'despacho' => ['Despacho', 'guia', ['PROGRAMADO'], 'antigua'],
        'transito' => ['En tránsito', 'guia', ['EN TRANSITO'], 'antigua'],
        'nacionalizacion' => ['Nacionalización', null, null, 'antigua'],
        'entrega' => ['Entrega', 'guia', ['ENTREGADO'], 'ultima'],
    ];

    public function __construct(private PanelDashboardService $dashboard)
    {
    }

    // ------------------------------------------------------------ configuración

    /** @return array<int, array{clave: string, nombre: string, fuente: ?string, estados: array, fecha: string, verde: int, amarillo: int}> */
    public function config(): array
    {
        $g = collect((array) (IaConfigService::json(self::CONST_PROCESO)['etapas'] ?? []))->keyBy('clave');
        $out = [];
        foreach (self::ETAPAS as $clave => [$nombre, $fuente, $estados, $fecha]) {
            $c = (array) $g->get($clave, []);
            $f = array_key_exists('fuente', $c) ? ($c['fuente'] ?: null) : $fuente;
            $f = isset(self::FUENTES[$f]) ? $f : null;
            // Sin configurar, los estados salen de las reglas del tablero; en las guías, del texto del estado.
            $est = array_key_exists('estados', $c) ? (array) $c['estados'] : ($estados ?? ($f && $f !== 'guia' ? self::ACTIVOS[$f] : []));
            $abierta = $fecha === 'antigua';
            $out[] = [
                'clave' => $clave,
                'nombre' => trim((string) ($c['nombre'] ?? '')) ?: $nombre,
                'fuente' => $f,
                'estados' => $f === 'guia' ? array_values(array_map('strval', $est)) : array_values(array_map('intval', $est)),
                'fecha' => in_array($c['fecha'] ?? $fecha, ['antigua', 'ultima'], true) ? ($c['fecha'] ?? $fecha) : $fecha,
                // La entrega ya terminó: por defecto nunca se pone en rojo.
                'verde' => max(0, (int) ($c['verde'] ?? ($abierta ? 3 : 9999))),
                'amarillo' => max(0, (int) ($c['amarillo'] ?? ($abierta ? 7 : 9999))),
                'color' => self::hex($c['color'] ?? null), // null = el de la card del tablero de su fuente
            ];
        }

        return $out;
    }

    public function guardar(array $etapas, ?array $semaforo = null): void
    {
        $validas = [];
        foreach ($etapas as $e) {
            $clave = $e['clave'] ?? '';
            if (! isset(self::ETAPAS[$clave])) {
                continue;
            }
            $f = isset(self::FUENTES[$e['fuente'] ?? '']) ? $e['fuente'] : null;
            $verde = max(0, (int) ($e['verde'] ?? 0));
            $validas[] = [
                'clave' => $clave,
                'nombre' => trim((string) ($e['nombre'] ?? '')) ?: self::ETAPAS[$clave][0],
                'fuente' => $f,
                'estados' => $f ? array_values(array_unique(array_map(fn ($v) => $f === 'guia' ? mb_strtoupper(trim((string) $v)) : (int) $v, (array) ($e['estados'] ?? [])))) : [],
                'fecha' => ($e['fecha'] ?? '') === 'ultima' ? 'ultima' : 'antigua',
                'verde' => $verde,
                'amarillo' => max($verde, (int) ($e['amarillo'] ?? 0)), // amarillo nunca antes que verde
                'color' => self::hex($e['color'] ?? null),
            ];
        }
        $sem = [];
        foreach (array_keys(self::SEMAFORO) as $k) {
            $sem[$k] = self::hex($semaforo[$k] ?? null) ?? self::SEMAFORO[$k];
        }
        IaConfigService::escribir(self::CONST_PROCESO, ['etapas' => $validas, 'semaforo' => $sem], 'Línea de proceso comercial: fuente, estados, días verde/amarillo/rojo y colores por etapa');
    }

    /** Colores del semáforo (puntos y líneas de la línea de proceso). */
    public const SEMAFORO = ['verde' => '#16a34a', 'amarillo' => '#f59e0b', 'rojo' => '#dc2626'];

    private static function hex(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
    }

    /** @return array{verde: string, amarillo: string, rojo: string} */
    public function colores(): array
    {
        $g = (array) (IaConfigService::json(self::CONST_PROCESO)['semaforo'] ?? []);
        $out = [];
        foreach (self::SEMAFORO as $k => $defecto) {
            $out[$k] = self::hex($g[$k] ?? null) ?? $defecto;
        }

        return $out;
    }

    /** Color de cada etapa mientras no se personalice: el de la card del tablero de su fuente (null si no tiene). */
    public function coloresPorDefecto(): array
    {
        $cards = $this->dashboard->coloresEtapas();
        $out = [];
        foreach ($this->config() as $c) {
            $card = ['solicitud' => 1, 'cotizacion' => 2, 'orden' => 3, 'guia' => 4][$c['fuente'] ?? ''] ?? null;
            $out[$c['clave']] = $card ? ($cards[$card]['fondo'] ?? null) : null;
        }

        return $out;
    }

    public function restablecer(): void
    {
        IaConfigService::borrar(self::CONST_PROCESO);
    }

    /** Catálogo para la pantalla de configuración: fuentes y estados disponibles de cada una. */
    public function catalogo(): array
    {
        $guias = DB::connection('mysql')->table('ordencompra_guias_entregas')->whereNotNull('estado')->where('estado', '<>', '')->distinct()->pluck('estado')->map(fn ($v) => mb_strtoupper(trim((string) $v)))->all();
        $guias = array_values(array_unique(array_merge(['PROGRAMADO', 'EN TRANSITO', 'ENTREGADO'], $guias)));
        sort($guias);

        $fuentes = [];
        foreach (self::FUENTES as $k => $nombre) {
            $fuentes[$k] = ['nombre' => $nombre, 'activos' => self::ACTIVOS[$k] ?? [], 'estados' => $k === 'guia'
                ? array_map(fn ($e) => ['id' => $e, 'nombre' => $e], $guias)
                : array_map(fn ($id, $n) => ['id' => $id, 'nombre' => "{$n} ({$id})"], array_keys(self::ESTADOS[$k]), self::ESTADOS[$k])];
        }

        return $fuentes;
    }

    // ------------------------------------------------------------ datos

    /** Color de una etapa según los días: verde hasta `verde`, amarillo hasta `amarillo`, rojo después. */
    private function semaforo(int $dias, array $c): string
    {
        return $dias <= $c['verde'] ? 'verde' : ($dias <= $c['amarillo'] ? 'amarillo' : 'rojo');
    }

    /**
     * Un administrador ve a todos; los demás, solo lo propio (campo de usuario según BUSCAR_POR_USUARIO_GESTIONADO).
     */
    public function datos(object $usuario, bool $todos): array
    {
        $todos = $todos && $usuario->isAdmin();
        $uid = $todos ? 0 : (int) $usuario->getKey();
        $config = $this->config();
        $semaforo = $this->colores();
        $clave = md5(json_encode([$config, $semaforo]));

        return Cache::remember("panel-proceso:{$uid}:{$clave}", 60, function () use ($uid, $config, $semaforo) {
            $anio = $this->dashboard->anioDesde();
            $desde = Carbon::create($anio, 1, 1)->toDateString();
            $uf = app(ActividadesService::class)->campoUsuario();
            $p = DB::getTablePrefix();
            $hoy = Carbon::today();
            $etapas = [];

            foreach ($config as $c) {
                $fila = ['clave' => $c['clave'], 'nombre' => $c['nombre'], 'card' => ['solicitud' => 1, 'cotizacion' => 2, 'orden' => 3, 'guia' => 4][$c['fuente'] ?? ''] ?? null, 'total' => 0, 'fecha' => null, 'dias' => 0, 'ultima' => $c['fecha'] === 'ultima',
                    'verde' => $c['verde'], 'amarillo' => $c['amarillo'], 'color' => 'pend', 'colorPropio' => $c['color'], 'pendiente' => ! $c['fuente'] || ! $c['estados']];
                if (! $fila['pendiente']) {
                    $n = count($c['estados']);
                    $in = implode(',', array_fill(0, $n, '?'));
                    $duenioBind = $uid ? [$uid] : [];
                    if ($c['fuente'] === 'guia') {
                        $ref = $c['fecha'] === 'ultima' ? 'MAX(t.fecha_entregado)' : 'MIN(COALESCE(t.fecha_envio_logistica, o.date_creation))';
                        $r = DB::selectOne("SELECT COUNT(*) n, {$ref} f FROM {$p}ordencompra_guias_entregas t INNER JOIN {$p}ordencompra_orden o ON o.rowid = t.fk_orden
                            WHERE UPPER(TRIM(t.estado)) IN ({$in}) AND DATE(o.date_creation) >= ?".($uid ? " AND o.{$uf} = ?" : ''), array_merge($c['estados'], [$desde], $duenioBind));
                    } else {
                        $d = $this->dashboard->definicionEtapa($c['fuente'], $uf);
                        $ref = $c['fecha'] === 'ultima' ? "MAX({$d['creacion']})" : "MIN({$d['refFecha']})";
                        $r = DB::selectOne("SELECT COUNT(*) n, {$ref} f FROM {$d['from']} WHERE t.status IN ({$in}) AND DATE({$d['creacion']}) >= ?".($uid ? " AND {$d['duenio']} = ?" : ''),
                            array_merge($c['estados'], [$desde], $duenioBind));
                    }
                    $fila['total'] = (int) $r->n;
                    $fila['fecha'] = $r->f ? substr((string) $r->f, 0, 10) : null;
                    $fila['dias'] = $fila['fecha'] ? max(0, Carbon::parse($fila['fecha'])->startOfDay()->diffInDays($hoy)) : 0;
                    $fila['color'] = $fila['total'] === 0 ? 'vacio' : $this->semaforo($fila['dias'], $c);
                }
                $etapas[] = $fila;
            }

            // La más atrasada: la de mayor exceso sobre su límite amarillo (solo etapas en rojo o amarillo).
            $peor = collect($etapas)->filter(fn ($e) => in_array($e['color'], ['rojo', 'amarillo'], true))->sortByDesc(fn ($e) => $e['dias'] - $e['amarillo'])->first();

            return ['desde' => $desde, 'etapas' => $etapas, 'masAtrasada' => $peor, 'alcance' => $uid ? 'mios' : 'todos', 'semaforo' => $semaforo];
        });
    }

    /**
     * Documentos que suman en una etapa (los más antiguos primero) con su último seguimiento.
     * Mismos filtros que `datos()`: fuente, estados, año desde y alcance.
     */
    public function documentos(object $usuario, bool $todos, string $clave, int $limite = 3000): array
    {
        $c = collect($this->config())->firstWhere('clave', $clave);
        abort_unless($c, 422, 'Etapa inválida.');
        if (! $c['fuente'] || ! $c['estados']) {
            return ['etapa' => $c['nombre'], 'total' => 0, 'items' => [], 'pendiente' => true];
        }
        $todos = $todos && $usuario->isAdmin();
        $uid = $todos ? 0 : (int) $usuario->getKey();
        $desde = Carbon::create($this->dashboard->anioDesde(), 1, 1)->toDateString();
        $uf = app(ActividadesService::class)->campoUsuario();
        $p = DB::getTablePrefix();
        $in = implode(',', array_fill(0, count($c['estados']), '?'));
        $hoy = Carbon::today();

        if ($c['fuente'] === 'guia') {
            $ref = 'COALESCE(t.fecha_envio_logistica, o.date_creation)';
            $rows = DB::select("SELECT o.rowid doc, o.ref, 'orden' modulo, t.estado, CONCAT_WS(' · ', NULLIF(t.transporte,''), NULLIF(t.guia,'')) titulo, {$ref} fecha, s.nom empresa,
                    TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) gestor, NULL valor
                FROM {$p}ordencompra_guias_entregas t INNER JOIN {$p}ordencompra_orden o ON o.rowid = t.fk_orden
                LEFT JOIN {$p}societe s ON s.rowid = o.fk_soc LEFT JOIN {$p}user u ON u.rowid = o.{$uf}
                WHERE UPPER(TRIM(t.estado)) IN ({$in}) AND DATE(o.date_creation) >= ?".($uid ? " AND o.{$uf} = ?" : '').'
                ORDER BY '.($c['fecha'] === 'ultima' ? 't.fecha_entregado DESC' : "{$ref} ASC")." LIMIT {$limite}",
                array_merge($c['estados'], [$desde], $uid ? [$uid] : []));
            $total = (int) DB::selectOne("SELECT COUNT(*) n FROM {$p}ordencompra_guias_entregas t INNER JOIN {$p}ordencompra_orden o ON o.rowid = t.fk_orden
                WHERE UPPER(TRIM(t.estado)) IN ({$in}) AND DATE(o.date_creation) >= ?".($uid ? " AND o.{$uf} = ?" : ''), array_merge($c['estados'], [$desde], $uid ? [$uid] : []))->n;
        } else {
            $d = $this->dashboard->definicionEtapa($c['fuente'], $uf);
            $titulo = $c['fuente'] === 'cotizacion' ? 't.label' : 't.asunto';
            $valor = $d['valor'] ?: 'NULL';
            $where = "t.status IN ({$in}) AND DATE({$d['creacion']}) >= ?".($uid ? " AND {$d['duenio']} = ?" : '');
            $b = array_merge($c['estados'], [$desde], $uid ? [$uid] : []);
            $rows = DB::select("SELECT t.rowid doc, t.ref, '{$d['modulo']}' modulo, t.status estado, {$titulo} titulo, {$d['refFecha']} fecha, s.nom empresa,
                    TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) gestor, {$valor} valor
                FROM {$d['from']} LEFT JOIN {$p}societe s ON s.rowid = {$d['socio']} LEFT JOIN {$p}user u ON u.rowid = {$d['duenio']}
                WHERE {$where} ORDER BY ".($c['fecha'] === 'ultima' ? "{$d['creacion']} DESC" : "{$d['refFecha']} ASC")." LIMIT {$limite}", $b);
            $total = (int) DB::selectOne("SELECT COUNT(*) n FROM {$d['from']} WHERE {$where}", $b)->n;
        }

        // Último seguimiento (observación) de cada documento.
        $ultimo = [];
        foreach (collect($rows)->groupBy('modulo') as $modulo => $grupo) {
            $m = ActividadesService::MODULOS[$modulo];
            $ids = $grupo->pluck('doc')->unique()->map(fn ($v) => (int) $v)->implode(',');
            foreach (DB::select("SELECT o.{$m['fk']} fk, o.mensaje, o.tms, TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) autor
                FROM {$p}{$m['tabla']} o LEFT JOIN {$p}user u ON u.rowid = o.fk_user_creat
                WHERE o.rowid IN (SELECT MAX(rowid) FROM {$p}{$m['tabla']} WHERE {$m['fk']} IN ({$ids}) GROUP BY {$m['fk']})") as $o) {
                $ultimo[$modulo][(int) $o->fk] = ['texto' => mb_strimwidth(ActividadesService::decodificar($o->mensaje), 0, 400, '…'), 'fecha' => $o->tms, 'autor' => $o->autor];
            }
        }

        $nombres = ['solicitud' => 'Solicitud', 'cotizacion' => 'Cotización', 'orden' => 'Orden de compra'];
        $estados = self::ESTADOS[$c['fuente']] ?? [];

        return [
            'etapa' => $c['nombre'], 'total' => $total, 'limite' => $limite, 'ultima' => $c['fecha'] === 'ultima',
            'items' => array_map(fn ($r) => [
                'doc' => (int) $r->doc, 'modulo' => $r->modulo, 'moduloNombre' => $nombres[$r->modulo] ?? $r->modulo, 'ref' => $r->ref,
                'empresa' => $r->empresa ?: 'Sin empresa', 'gestor' => $r->gestor ?: null,
                'titulo' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $r->titulo)))), 0, 110, '…'),
                'estado' => $c['fuente'] === 'guia' ? (string) $r->estado : ($estados[(int) $r->estado] ?? 'Estado '.$r->estado),
                'fecha' => $r->fecha ? substr((string) $r->fecha, 0, 10) : null,
                'dias' => $r->fecha ? max(0, Carbon::parse($r->fecha)->startOfDay()->diffInDays($hoy)) : null,
                'valor' => $r->valor ? round((float) $r->valor) : null,
                'seguimiento' => $ultimo[$r->modulo][(int) $r->doc] ?? null,
            ], $rows),
        ];
    }
}
