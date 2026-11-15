<?php

namespace App\Services\Panel;

use App\Services\Ia\IaConfigService;
use App\Http\Controllers\Panel\Solicitudes\SolicitudesGridController;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tablero (kanban) de solicitudes: una columna por etapa, tarjetas con la información de seguimiento y
 * movimiento entre columnas. Los estados de cotización y orden de compra los fijan sus propios módulos,
 * así que esas tarjetas se muestran pero no se arrastran, y nada se puede soltar en la columna de órdenes.
 */
class SolicitudesTableroService
{
    public const POR_COLUMNA = 25;

    /** Solicitudes que se trabajan en el tablero: las «activas» del tablero principal (activa, en cotización de proveedor, importación y cotización enviada). */
    public const ESTADOS_TABLERO = [1, 2, 3, 4, 5, 6];

    /** Estados que se pueden asignar a mano desde el menú de la tarjeta (más el cierre, que pide motivo). */
    public const MOVIBLES = [1, 2, 3, 4, 5, 6, 9];

    /** Días de mora (desde la creación) donde termina cada nivel; el último no tiene tope. */
    public const MORA_VERDE = 15;
    public const MORA_AMBAR = 30;

    /** Nombre de la constante donde se guarda la configuración editable de las columnas. */
    public const CONST_COLUMNAS = 'PANEL_SOLICITUDES_TABLERO_COLUMNAS';

    /** Valores de «contacto»: cualquiera, solo con el próximo contacto vencido (o para hoy) o solo sin vencer. */
    public const CONTACTOS = ['cualquiera', 'vencido', 'no_vencido'];

    /**
     * Columnas por defecto y sus reglas. Cada solicitud cae en la PRIMERA columna (en el orden configurado) cuyas reglas cumple:
     * estados (vacío = todos los del tablero), mora mínima y máxima en días desde la creación (null = sin límite) y estado del contacto.
     */
    public const COLUMNAS = [
        'atrasadas' => ['titulo' => 'Atrasadas', 'icono' => 'fa-fire', 'color' => '#dc2626', 'estados' => [], 'moraMin' => self::MORA_AMBAR + 1, 'moraMax' => null, 'contacto' => 'no_vencido'],
        'atencion' => ['titulo' => 'Atención', 'icono' => 'fa-triangle-exclamation', 'color' => '#d97706', 'estados' => [], 'moraMin' => self::MORA_VERDE + 1, 'moraMax' => self::MORA_AMBAR, 'contacto' => 'no_vencido'],
        'contactar' => ['titulo' => 'Por contactar', 'icono' => 'fa-bell', 'color' => '#7c3aed', 'estados' => [], 'moraMin' => null, 'moraMax' => null, 'contacto' => 'vencido'],
        'aldia' => ['titulo' => 'Al día', 'icono' => 'fa-circle-check', 'color' => '#16a34a', 'estados' => [], 'moraMin' => null, 'moraMax' => self::MORA_VERDE, 'contacto' => 'no_vencido'],
    ];

    private string $p;

    public function __construct(private ActividadesService $actividades, private SolicitudFichaService $ficha)
    {
        $this->p = DB::getTablePrefix();
    }

    /**
     * Columnas vigentes, en orden: las por defecto con lo que haya guardado encima (título, reglas y orden).
     *
     * @return array<string, array<string, mixed>>
     */
    public function columnas(): array
    {
        $guardado = IaConfigService::json(self::CONST_COLUMNAS) ?? [];
        $orden = array_values(array_unique(array_merge(
            array_filter((array) ($guardado['orden'] ?? []), fn ($k) => isset(self::COLUMNAS[$k])),
            array_keys(self::COLUMNAS),
        )));
        $out = [];
        foreach ($orden as $k) {
            $c = array_merge(self::COLUMNAS[$k], array_intersect_key((array) ($guardado['columnas'][$k] ?? []), self::COLUMNAS[$k]));
            $c['ayuda'] = $this->ayuda($c);
            $out[$k] = $c;
        }

        return $out;
    }

    /** Texto corto que describe las reglas de una columna. */
    private function ayuda(array $c): string
    {
        $p = [];
        $min = $c['moraMin'];
        $max = $c['moraMax'];
        if ($min !== null && $max !== null) {
            $p[] = "De $min a $max días de mora";
        } elseif ($min !== null) {
            $p[] = "Más de ".($min - 1)." días de mora";
        } elseif ($max !== null) {
            $p[] = "Hasta $max días de mora";
        }
        if ($c['contacto'] === 'vencido') {
            $p[] = 'próximo contacto vencido o para hoy';
        } elseif ($c['contacto'] === 'no_vencido') {
            $p[] = 'sin contacto vencido';
        }
        if ($c['estados']) {
            $p[] = 'estados: '.implode(', ', array_map(fn ($e) => SolicitudesGridController::ESTADOS[(string) $e] ?? $e, $c['estados']));
        }

        return $p ? ucfirst(implode(' · ', $p)) : 'Todas las solicitudes que no entraron en una columna anterior';
    }

    /**
     * Guarda título, reglas y orden de las columnas. Lo que no cambie respecto al valor por defecto no se guarda.
     *
     * @param  array<int, array<string, mixed>>  $columnas  en el orden deseado; cada una con 'clave' y los campos editables
     */
    public function guardarColumnas(array $columnas): void
    {
        $orden = [];
        $guardar = [];
        foreach ($columnas as $c) {
            $k = (string) ($c['clave'] ?? '');
            if (! isset(self::COLUMNAS[$k]) || in_array($k, $orden, true)) {
                continue;
            }
            $orden[] = $k;
            $c += self::COLUMNAS[$k]; // lo que no venga se queda con el valor por defecto (así funciona «Restablecer»)
            $titulo = trim((string) ($c['titulo'] ?? ''));
            $nuevo = [
                'titulo' => $titulo !== '' ? mb_substr($titulo, 0, 40) : self::COLUMNAS[$k]['titulo'],
                'estados' => array_values(array_intersect(array_map('intval', (array) ($c['estados'] ?? [])), self::ESTADOS_TABLERO)),
                'moraMin' => isset($c['moraMin']) && $c['moraMin'] !== '' ? max(0, (int) $c['moraMin']) : null,
                'moraMax' => isset($c['moraMax']) && $c['moraMax'] !== '' ? max(0, (int) $c['moraMax']) : null,
                'contacto' => in_array($c['contacto'] ?? '', self::CONTACTOS, true) ? $c['contacto'] : 'cualquiera',
            ];
            $dif = array_filter($nuevo, fn ($v, $campo) => $v !== self::COLUMNAS[$k][$campo], ARRAY_FILTER_USE_BOTH);
            if ($dif) {
                $guardar[$k] = $dif;
            }
        }
        $porDefecto = $orden === array_keys(self::COLUMNAS);
        $valor = ['columnas' => $guardar] + ($porDefecto ? [] : ['orden' => $orden]);
        $guardar || ! $porDefecto
            ? IaConfigService::escribir(self::CONST_COLUMNAS, $valor, 'Columnas del tablero de solicitudes: títulos, reglas y orden')
            : IaConfigService::borrar(self::CONST_COLUMNAS);
    }

    /**
     * Cuántas solicitudes hay en cada columna del tablero (mismas reglas que las tarjetas). $uid limita a las de un usuario.
     *
     * @return array<int, array{clave: string, titulo: string, color: string, n: int}>
     */
    public function conteos(?int $uid = null): array
    {
        $mora = 'DATEDIFF(CURDATE(), DATE(t.date_creation))';
        $contactar = 't.fecha_proximo_contacto IS NOT NULL AND DATE(t.fecha_proximo_contacto) <= CURDATE()';
        $base = 't.status IN ('.implode(',', self::ESTADOS_TABLERO).')';
        $b = [];
        if ($uid) {
            $base .= ' AND t.'.$this->actividades->campoUsuario().' = ?';
            $b[] = $uid;
        }
        $out = [];
        $previas = '';
        foreach ($this->columnas() as $k => $c) {
            $cond = $this->condicion($c, $mora, $contactar);
            $n = (int) (DB::selectOne("SELECT COUNT(*) n FROM {$this->p}solicitudes_solicitud t WHERE $base$previas AND $cond", $b)->n ?? 0);
            $extremo = fn (string $orden) => ($f = DB::selectOne("SELECT t.rowid id, t.ref, t.date_creation fecha, $mora dias FROM {$this->p}solicitudes_solicitud t WHERE $base$previas AND $cond ORDER BY t.date_creation $orden, t.rowid $orden LIMIT 1", $b))
                ? ['id' => (int) $f->id, 'ref' => $f->ref, 'fecha' => substr((string) $f->fecha, 0, 10), 'dias' => max(0, (int) $f->dias)] : null;
            $out[] = ['clave' => $k, 'titulo' => $c['titulo'], 'color' => $c['color'], 'n' => $n, 'ayuda' => $c['ayuda'], 'masAntigua' => $n ? $extremo('ASC') : null, 'masNueva' => $n ? $extremo('DESC') : null];
            $previas .= " AND NOT $cond";
        }

        return $out;
    }

    /** Condición SQL de una columna según sus reglas (sin tener en cuenta las columnas anteriores). */
    private function condicion(array $c, string $mora, string $contactar): string
    {
        $w = ['1=1'];
        if ($c['estados']) {
            $w[] = 't.status IN ('.implode(',', array_map('intval', $c['estados'])).')';
        }
        if ($c['moraMin'] !== null) {
            $w[] = "$mora >= ".(int) $c['moraMin'];
        }
        if ($c['moraMax'] !== null) {
            $w[] = "$mora <= ".(int) $c['moraMax'];
        }
        if ($c['contacto'] === 'vencido') {
            $w[] = "($contactar)";
        } elseif ($c['contacto'] === 'no_vencido') {
            $w[] = "NOT ($contactar)";
        }

        return '('.implode(' AND ', $w).')';
    }

    /** Definición para el cliente (sin datos): columnas y estados que se pueden asignar. */
    public function definicion(): array
    {
        $columnas = [];
        foreach ($this->columnas() as $clave => $c) {
            $columnas[] = ['clave' => $clave] + $c;
        }
        $estados = [];
        foreach (self::MOVIBLES as $e) {
            $estados[] = ['id' => $e, 'nombre' => SolicitudesGridController::ESTADOS[(string) $e] ?? "Estado $e"];
        }

        return ['columnas' => $columnas, 'estados' => $estados, 'estadosTablero' => array_map(fn ($e) => ['id' => $e, 'nombre' => SolicitudesGridController::ESTADOS[(string) $e] ?? "Estado $e"], self::ESTADOS_TABLERO)];
    }

    /**
     * Tarjetas de una columna. Filtros: q (ref, cliente o asunto) y estados (lista de estados de la solicitud).
     *
     * @return array{total: int, items: array<int, array<string, mixed>>}
     */
    public function columna(object $usuario, string $clave, array $filtros, int $skip = 0): array
    {
        $columnas = $this->columnas();
        if (! isset($columnas[$clave])) {
            throw new InvalidArgumentException('Columna inválida.');
        }
        $estados = array_values(array_intersect(array_map('intval', $filtros['estados'] ?? []), self::ESTADOS_TABLERO)) ?: self::ESTADOS_TABLERO;
        $b = [];
        $where = 't.status IN ('.implode(',', $estados).')';

        if (! $usuario->isAdmin()) {
            $where .= ' AND t.'.$this->actividades->campoUsuario().' = ?';
            $b[] = $usuario->getKey();
        }
        $mora = 'DATEDIFF(CURDATE(), DATE(t.date_creation))';
        $contactar = 't.fecha_proximo_contacto IS NOT NULL AND DATE(t.fecha_proximo_contacto) <= CURDATE()';
        // Cae en la primera columna cuyas reglas cumple: las de esta y que no cumpla ninguna de las anteriores.
        foreach ($columnas as $k => $c) {
            if ($k === $clave) {
                $where .= ' AND '.$this->condicion($c, $mora, $contactar);
                break;
            }
            $where .= ' AND NOT '.$this->condicion($c, $mora, $contactar);
        }
        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $where .= ' AND (t.ref LIKE ? OR s.nom LIKE ? OR t.asunto LIKE ?)';
            array_push($b, $like, $like, $like);
        }
        if (! empty($filtros['desde'])) {
            $where .= ' AND DATE(t.date_creation) >= ?';
            $b[] = $filtros['desde'];
        }
        if (! empty($filtros['hasta'])) {
            $where .= ' AND DATE(t.date_creation) <= ?';
            $b[] = $filtros['hasta'];
        }
        $desde = "FROM {$this->p}solicitudes_solicitud t
            LEFT JOIN {$this->p}societe s ON s.rowid = t.fk_soc
            LEFT JOIN {$this->p}user u ON u.rowid = t.fk_user_creat";
        $total = (int) (DB::selectOne("SELECT COUNT(*) n $desde WHERE $where", $b)->n ?? 0);

        // Primero lo más urgente: en «Por contactar» el contacto más vencido; en las demás, la mayor mora.
        $filas = DB::select("SELECT t.rowid, t.ref, t.fk_soc, s.nom cliente, t.asunto, t.status, t.envio_email,
                t.fecha_solicitud, t.date_creation, t.fecha_proximo_contacto, t.ultima_respuesta,
                DATEDIFF(CURDATE(), DATE(t.date_creation)) mora,
                CONCAT_WS(' ', u.firstname, u.lastname) creador,
                (SELECT COUNT(*) FROM {$this->p}solicitudes_solicitudline l WHERE l.fk_solicitud = t.rowid) productos,
                (SELECT o.mensaje FROM {$this->p}observaciones_solicitudes o WHERE o.fk_solicitud = t.rowid ORDER BY o.rowid DESC LIMIT 1) ultimo_mensaje,
                (SELECT o.tms FROM {$this->p}observaciones_solicitudes o WHERE o.fk_solicitud = t.rowid ORDER BY o.rowid DESC LIMIT 1) ultimo_fecha
            $desde WHERE $where ORDER BY ".($clave === 'contactar' ? 't.fecha_proximo_contacto ASC, ' : '')."mora DESC, t.rowid DESC LIMIT ".self::POR_COLUMNA.' OFFSET '.max(0, $skip), $b);

        return ['total' => $total, 'items' => array_map(fn ($o) => $this->tarjeta($o), $filas)];
    }

    private function tarjeta(object $o): array
    {
        $estado = (int) $o->status;
        $texto = ActividadesService::decodificar($o->ultimo_mensaje);
        $prox = $o->fecha_proximo_contacto ? substr((string) $o->fecha_proximo_contacto, 0, 10) : null;

        return [
            'id' => (int) $o->rowid,
            'ref' => $o->ref,
            'cliente' => $o->cliente,
            'asunto' => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $o->asunto), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            'estado' => $estado,
            'estadoNombre' => SolicitudesGridController::ESTADOS[(string) $estado] ?? "Estado $estado",
            'mora' => max(0, (int) $o->mora),
            'fechaSolicitud' => $o->fecha_solicitud ? substr((string) $o->fecha_solicitud, 0, 10) : null,
            'proximoContacto' => $prox,
            'contactoVencido' => $prox !== null && $prox <= now(ActividadesService::TZ)->toDateString(),
            'ultimaRespuesta' => $o->ultima_respuesta ? substr((string) $o->ultima_respuesta, 0, 10) : null,
            'correoEnviado' => (bool) $o->envio_email,
            'productos' => (int) $o->productos,
            'creador' => $o->creador,
            'ultimoSeguimiento' => $texto !== '' ? ['texto' => mb_strimwidth($texto, 0, 140, '…'), 'fecha' => $o->ultimo_fecha ? substr((string) $o->ultimo_fecha, 0, 10) : null] : null,
        ];
    }

    /**
     * Mueve una solicitud a otra etapa. $estado es el estado destino; $motivo solo aplica al cerrar.
     * Cerrar y reabrir siguen las reglas de la ficha (dejan historial y reactivan la cotización exterior).
     */
    public function mover(object $usuario, object $sol, int $estado, ?int $motivo): void
    {
        $actual = (int) $sol->status;
        if (! in_array($actual, self::MOVIBLES, true)) {
            throw new InvalidArgumentException('Esta solicitud ya está en cotización u orden de compra y su estado lo gobierna ese documento.');
        }
        if (! in_array($estado, self::MOVIBLES, true)) {
            throw new InvalidArgumentException('Esa etapa no admite mover solicitudes a mano.');
        }
        if ($estado === $actual) {
            return;
        }
        if ($estado === SolicitudFichaService::CERRADA) {
            if (! $motivo) {
                throw new InvalidArgumentException('Elige el motivo del cierre.');
            }
            $this->ficha->cerrar($usuario, $sol, $motivo);

            return;
        }
        if ($actual === SolicitudFichaService::CERRADA) {
            $this->ficha->reabrir($usuario, $sol);
            if ($estado === SolicitudFichaService::ACTIVO) {
                return;
            }
            $sol = $this->ficha->solicitud((int) $sol->rowid);
        }

        DB::transaction(function () use ($usuario, $sol, $estado) {
            DB::table('solicitudes_solicitud')->where('rowid', $sol->rowid)->update(['status' => $estado, 'fk_user_modif' => $usuario->getKey()]);
            DB::table('solicitudes_historico_estados')->insert([
                'fk_solicitud' => $sol->rowid, 'fk_user' => $usuario->getKey(), 'fecha' => now(),
                'estado' => SolicitudesGridController::ESTADOS[(string) $estado] ?? "Estado $estado",
            ]);
        });
    }
}
