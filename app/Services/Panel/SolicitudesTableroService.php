<?php

namespace App\Services\Panel;

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

    /** Columnas de la más a la menos urgente. Cada solicitud cae en una sola: «Por contactar» manda sobre la mora. */
    public const COLUMNAS = [
        'atrasadas' => ['titulo' => 'Atrasadas', 'icono' => 'fa-fire', 'color' => '#dc2626', 'ayuda' => 'Más de 30 días de mora'],
        'atencion' => ['titulo' => 'Atención', 'icono' => 'fa-triangle-exclamation', 'color' => '#d97706', 'ayuda' => 'De 16 a 30 días de mora'],
        'contactar' => ['titulo' => 'Por contactar', 'icono' => 'fa-bell', 'color' => '#7c3aed', 'ayuda' => 'Próximo contacto vencido o para hoy'],
        'aldia' => ['titulo' => 'Al día', 'icono' => 'fa-circle-check', 'color' => '#16a34a', 'ayuda' => 'Hasta 15 días de mora'],
    ];

    private string $p;

    public function __construct(private ActividadesService $actividades, private SolicitudFichaService $ficha)
    {
        $this->p = DB::getTablePrefix();
    }

    /** Definición para el cliente (sin datos): columnas y estados que se pueden asignar. */
    public function definicion(): array
    {
        $columnas = [];
        foreach (self::COLUMNAS as $clave => $c) {
            $columnas[] = ['clave' => $clave] + $c;
        }
        $estados = [];
        foreach (self::MOVIBLES as $e) {
            $estados[] = ['id' => $e, 'nombre' => SolicitudesGridController::ESTADOS[(string) $e] ?? "Estado $e"];
        }

        return ['columnas' => $columnas, 'estados' => $estados];
    }

    /**
     * Tarjetas de una columna. Filtros: q (ref, cliente o asunto) y estados (lista de estados de la solicitud).
     *
     * @return array{total: int, items: array<int, array<string, mixed>>}
     */
    public function columna(object $usuario, string $clave, array $filtros, int $skip = 0): array
    {
        if (! isset(self::COLUMNAS[$clave])) {
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
        $where .= match ($clave) {
            'contactar' => " AND $contactar",
            'aldia' => " AND NOT ($contactar) AND $mora <= ".self::MORA_VERDE,
            'atencion' => " AND NOT ($contactar) AND $mora > ".self::MORA_VERDE." AND $mora <= ".self::MORA_AMBAR,
            default => " AND NOT ($contactar) AND $mora > ".self::MORA_AMBAR,
        };
        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $where .= ' AND (t.ref LIKE ? OR s.nom LIKE ? OR t.asunto LIKE ?)';
            array_push($b, $like, $like, $like);
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
