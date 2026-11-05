<?php

namespace App\Services\Panel;

use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/**
 * Tablero (kanban) de cotizaciones activas (validadas y enviadas), con la misma estructura del de solicitudes: una columna por
 * antigüedad del último movimiento (la fecha de la cotización o su último seguimiento, lo que sea más reciente). La fecha de retoma
 * no sirve de criterio: casi todas las cotizaciones la traen vencida. El estado de una cotización lo gobierna su propio módulo,
 * así que las tarjetas no se arrastran.
 */
class CotizacionesTableroService
{
    public const POR_COLUMNA = 25;
    public const ESTADOS = [1 => 'Validada', 2 => 'Enviada'];
    public const MORA_VERDE = 30;
    public const MORA_AMBAR = 90;

    public const COLUMNAS = [
        'atrasadas' => ['titulo' => 'Atrasadas', 'icono' => 'fa-fire', 'color' => '#dc2626', 'ayuda' => 'Más de 90 días sin movimiento'],
        'atencion' => ['titulo' => 'Atención', 'icono' => 'fa-triangle-exclamation', 'color' => '#d97706', 'ayuda' => 'De 31 a 90 días sin movimiento'],
        'aldia' => ['titulo' => 'Al día', 'icono' => 'fa-circle-check', 'color' => '#16a34a', 'ayuda' => 'Movimiento en los últimos 30 días'],
    ];

    private string $p;

    public function __construct(private ActividadesService $actividades)
    {
        $this->p = DB::getTablePrefix();
    }

    public function definicion(): array
    {
        $columnas = [];
        foreach (self::COLUMNAS as $clave => $c) {
            $columnas[] = ['clave' => $clave] + $c;
        }
        $estados = [];
        foreach (self::ESTADOS as $id => $nombre) {
            $estados[] = ['id' => $id, 'nombre' => $nombre];
        }

        return ['columnas' => $columnas, 'estados' => $estados];
    }

    /** @return array{total: int, items: array<int, array<string, mixed>>} */
    public function columna(object $usuario, string $clave, ?string $q, ?int $estado, int $skip = 0): array
    {
        if (! isset(self::COLUMNAS[$clave])) {
            throw new InvalidArgumentException('Columna inválida.');
        }
        $estados = $estado && isset(self::ESTADOS[$estado]) ? [$estado] : array_keys(self::ESTADOS);
        $where = 't.status IN ('.implode(',', $estados).')';
        $b = [];
        if (! $usuario->isAdmin()) {
            $where .= ' AND t.'.$this->actividades->campoUsuario().' = ?';
            $b[] = $usuario->getKey();
        }
        // Días desde el último movimiento: la fecha de la cotización o su último seguimiento, el más reciente.
        $mora = "DATEDIFF(CURDATE(), GREATEST(DATE(COALESCE(t.fecha, t.date_creation)), COALESCE(DATE(ob.ult), '1970-01-01')))";
        $where .= match ($clave) {
            'aldia' => " AND $mora <= ".self::MORA_VERDE,
            'atencion' => " AND $mora > ".self::MORA_VERDE." AND $mora <= ".self::MORA_AMBAR,
            default => " AND $mora > ".self::MORA_AMBAR,
        };
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $where .= ' AND (t.ref LIKE ? OR s.nom LIKE ? OR t.label LIKE ? OR sol.ref LIKE ?)';
            array_push($b, $like, $like, $like, $like);
        }
        $desde = "FROM {$this->p}cotizaciones_clientecotizacion t
            LEFT JOIN {$this->p}societe s ON s.rowid = t.fk_soc
            LEFT JOIN {$this->p}solicitudes_solicitud sol ON sol.rowid = t.fk_solicitud
            LEFT JOIN {$this->p}user u ON u.rowid = t.fk_user_creat
            LEFT JOIN (SELECT fk_clientecotizacion f, MAX(rowid) rid, MAX(tms) ult FROM {$this->p}observaciones_cotizaciones GROUP BY fk_clientecotizacion) ob ON ob.f = t.rowid
            LEFT JOIN {$this->p}observaciones_cotizaciones om ON om.rowid = ob.rid";
        $total = (int) (DB::selectOne("SELECT COUNT(*) n $desde WHERE $where", $b)->n ?? 0);
        $filas = DB::select("SELECT t.rowid, t.ref, s.nom cliente, t.label, sol.ref sol_ref, sol.asunto sol_asunto, t.status, t.fecha, DATE_ADD(DATE(t.fecha), INTERVAL COALESCE(t.validez_oferta, 0) DAY) valida_hasta, t.validez_oferta,
                CASE WHEN COALESCE(t.tipo_moneda,0)=1 AND COALESCE(t.trm,0)>0 THEN COALESCE(t.total_bruto,0)*t.trm ELSE COALESCE(t.total_bruto,0) END valor,
                t.total_bruto, t.tipo_moneda, $mora mora, CONCAT_WS(' ', u.firstname, u.lastname) creador,
                om.mensaje ultimo_mensaje, ob.ult ultimo_fecha
            $desde WHERE $where ORDER BY ".'mora DESC, t.rowid DESC LIMIT '.self::POR_COLUMNA.' OFFSET '.max(0, $skip), $b);

        return ['total' => $total, 'items' => array_map(function ($o) {
            $texto = ActividadesService::decodificar($o->ultimo_mensaje);
            $hasta = $o->fecha && (int) $o->validez_oferta > 0 ? substr((string) $o->valida_hasta, 0, 10) : null;

            return [
                'id' => (int) $o->rowid,
                'ref' => $o->ref,
                'cliente' => $o->cliente,
                'titulo' => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($o->label ?: $o->sol_asunto)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                'solicitud' => $o->sol_ref,
                'estado' => (int) $o->status,
                'estadoNombre' => self::ESTADOS[(int) $o->status] ?? 'Estado '.$o->status,
                'mora' => max(0, (int) $o->mora),
                'fecha' => $o->fecha ? substr((string) $o->fecha, 0, 10) : null,
                'validaHasta' => $hasta,
                'vencida' => $hasta !== null && $hasta < now(ActividadesService::TZ)->toDateString(),
                'valor' => round((float) $o->valor),
                'creador' => $o->creador,
                'ultimoSeguimiento' => $texto !== '' ? ['texto' => mb_strimwidth($texto, 0, 140, '…'), 'fecha' => $o->ultimo_fecha ? substr((string) $o->ultimo_fecha, 0, 10) : null] : null,
            ];
        }, $filas)];
    }
}
