<?php

namespace App\Services\Panel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tareas del programador. Mismo modelo de datos que el módulo «solubitacora» de Dolibarr, para que lo que se crea aquí
 * también se vea en el sistema anterior (y al revés):
 *  - solubitacora_tareas:      la tarea (título, fecha/hora, prioridad, recurrencia, elemento relacionado type_rq + ref_rq = id).
 *  - solubitacora_seguimiento: sus integrantes (uno por usuario).
 * Estados: 40 = activa, 38 = cerrada. Recurrencia: 1 única, 2 diaria, 3 semanal, 4 mensual (no se generan filas: se calcula la próxima).
 */
class TareasService
{
    public const TZ = ActividadesService::TZ;

    public const ACTIVA = 40;

    public const CERRADA = 38;

    public const RECURRENCIAS = [1 => 'Única', 2 => 'Diaria', 3 => 'Semanal', 4 => 'Mensual'];

    /** Elementos del sistema a los que se puede relacionar una tarea. `modulo` = el que sabe abrir el panel de documentos. */
    public const TIPOS = [
        'solicitud' => ['nombre' => 'Solicitud', 'icono' => 'fa-inbox', 'card' => 1, 'modulo' => 'solicitud', 'tabla' => 'solicitudes_solicitud', 'ref' => 't.ref', 'soc' => 't.fk_soc', 'titulo' => 't.asunto'],
        'cotizacion' => ['nombre' => 'Cotización', 'icono' => 'fa-file-invoice-dollar', 'card' => 2, 'modulo' => 'cotizacion', 'tabla' => 'cotizaciones_clientecotizacion', 'ref' => 't.ref', 'soc' => 't.fk_soc', 'titulo' => 't.label'],
        'cotiext' => ['nombre' => 'Solicitud a proveedor', 'icono' => 'fa-industry', 'card' => 4, 'modulo' => null, 'tabla' => 'cotiext_exterior', 'ref' => 't.ref', 'soc' => 't.fk_soc', 'titulo' => 'NULL'],
        'orden' => ['nombre' => 'Orden de compra', 'icono' => 'fa-shopping-cart', 'card' => 3, 'modulo' => 'orden', 'tabla' => 'ordencompra_orden', 'ref' => 't.ref', 'soc' => 't.fk_soc', 'titulo' => 't.asunto'],
        'importacion' => ['nombre' => 'Importación', 'icono' => 'fa-ship', 'card' => 4, 'modulo' => null, 'tabla' => 'importacion_importaciones', 'ref' => 't.ref', 'soc' => 't.fk_soc', 'titulo' => 'NULL'],
        'factura' => ['nombre' => 'Factura', 'icono' => 'fa-file-invoice', 'card' => 4, 'modulo' => null, 'tabla' => 'facture', 'ref' => 't.ref', 'soc' => 't.fk_soc', 'titulo' => 'NULL'],
        'cliente' => ['nombre' => 'Cliente', 'icono' => 'fa-building', 'card' => 4, 'modulo' => null, 'tabla' => 'societe', 'ref' => 't.nom', 'soc' => 't.rowid', 'titulo' => 'NULL'],
        'producto' => ['nombre' => 'Producto', 'icono' => 'fa-box', 'card' => 4, 'modulo' => null, 'tabla' => 'product', 'ref' => 't.ref', 'soc' => 'NULL', 'titulo' => 't.label'],
    ];

    private string $p;

    public function __construct(private ActividadesService $actividades)
    {
        $this->p = DB::getTablePrefix();
    }

    // ---------------------------------------------------------- texto (el sistema anterior guarda la descripción con utf8_encode)

    public static function codificar(string $html): string
    {
        return mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }

    public static function decodificar(?string $t): string
    {
        $t = (string) $t;
        $d = @mb_convert_encoding($t, 'ISO-8859-1', 'UTF-8');

        return $d !== false && mb_check_encoding($d, 'UTF-8') ? $d : $t;
    }

    /** Descripción en texto plano (la guarda como HTML). */
    public static function textoPlano(?string $t, int $max = 4000): string
    {
        $h = preg_replace('/<\s*(br|\/p|\/li|\/tr)\s*\/?>/i', "\n", self::decodificar($t));

        return mb_substr(trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8'))), 0, $max);
    }

    // ---------------------------------------------------------- catálogos

    public function catalogos(): array
    {
        $pr = [];
        foreach (DB::select("SELECT MIN(rowid) id, label, MIN(color) color, MIN(fontColor) fontColor FROM {$this->p}c_solubitacora_prioridad GROUP BY label ORDER BY MIN(rowid)") as $r) {
            $pr[] = ['id' => (int) $r->id, 'label' => $r->label, 'color' => $r->color, 'fontColor' => $r->fontColor];
        }
        $cat = array_map(fn ($r) => ['id' => (int) $r->rowid, 'label' => $r->label, 'color' => $r->color], DB::select("SELECT rowid, label, color FROM {$this->p}c_solubitacora_categoria ORDER BY label"));

        return ['prioridades' => $pr, 'categorias' => $cat, 'usuarios' => $this->usuarios(), 'recurrencias' => self::RECURRENCIAS];
    }

    public function usuarios(): array
    {
        return array_map(fn ($u) => ['id' => (int) $u->rowid, 'nombre' => trim($u->firstname.' '.$u->lastname) ?: $u->login],
            DB::select("SELECT rowid, firstname, lastname, login FROM {$this->p}user WHERE statut = 1 AND (fk_soc IS NULL OR fk_soc = 0) ORDER BY firstname, lastname"));
    }

    // ---------------------------------------------------------- elementos relacionables

    /** Búsqueda de elementos para relacionar. Solicitudes, cotizaciones y órdenes: los del usuario (todos si es admin). */
    public function buscar(object $usuario, string $tipo, string $q): array
    {
        $cfg = self::TIPOS[$tipo] ?? throw new InvalidArgumentException('Tipo inválido.');
        $q = trim($q);

        if ($cfg['modulo']) {
            return array_map(fn ($d) => $this->itemElemento($tipo, (int) $d['id'], $d['ref'], $d['empresa'], $d['titulo'], $d['estado']), $this->actividades->buscar($usuario, $q, $cfg['modulo']));
        }
        if (in_array($tipo, ['cliente', 'producto'], true) && mb_strlen($q) < 2) {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
        $busca = match ($tipo) {
            'cliente' => ' AND (t.nom LIKE ? OR t.code_client LIKE ? OR t.siren LIKE ?)',
            'producto' => ' AND (t.ref LIKE ? OR t.label LIKE ?)',
            default => ' AND (t.ref LIKE ? OR s.nom LIKE ?)',
        };
        $n = substr_count($busca, '?');
        $b = $q === '' ? [] : array_fill(0, $n, $like);
        $join = $cfg['soc'] === 't.rowid' || $cfg['soc'] === 'NULL' ? '' : " LEFT JOIN {$this->p}societe s ON s.rowid = {$cfg['soc']}";
        if ($join === '' && $tipo === 'cliente') {
            $busca = str_replace('s.nom', 't.nom', $busca);
        }
        $empresa = $tipo === 'cliente' ? 't.nom' : ($join ? 's.nom' : 'NULL');
        $sql = "SELECT t.rowid id, {$cfg['ref']} ref, {$empresa} empresa, {$cfg['titulo']} titulo FROM {$this->p}{$cfg['tabla']} t{$join} WHERE 1=1".($q === '' ? '' : $busca).' ORDER BY t.rowid DESC LIMIT 8';

        return array_map(fn ($r) => $this->itemElemento($tipo, (int) $r->id, (string) $r->ref, $r->empresa, $r->titulo), DB::select($sql, $b));
    }

    private function itemElemento(string $tipo, int $id, string $ref, ?string $empresa, ?string $titulo, ?string $estado = null): array
    {
        $c = self::TIPOS[$tipo];

        return ['tipo' => $tipo, 'tipoNombre' => $c['nombre'], 'icono' => $c['icono'], 'card' => $c['card'], 'modulo' => $c['modulo'], 'id' => $id, 'ref' => $ref, 'empresa' => $empresa ?: ($tipo === 'cliente' ? $ref : ''), 'titulo' => $titulo ? mb_strimwidth(trim(strip_tags($titulo)), 0, 100, '…') : '', 'estado' => $estado];
    }

    /**
     * Datos de varios elementos a la vez.
     *
     * @param  array<int, array{0: string, 1: int}>  $pares  [tipo, id]
     * @return array<string, array> clave "tipo:id"
     */
    public function elementos(array $pares): array
    {
        $porTipo = [];
        foreach ($pares as [$t, $id]) {
            if (isset(self::TIPOS[$t]) && $id > 0) {
                $porTipo[$t][$id] = $id;
            }
        }
        $out = [];
        foreach ($porTipo as $tipo => $ids) {
            $c = self::TIPOS[$tipo];
            $join = $c['soc'] === 't.rowid' || $c['soc'] === 'NULL' ? '' : " LEFT JOIN {$this->p}societe s ON s.rowid = {$c['soc']}";
            $empresa = $tipo === 'cliente' ? 't.nom' : ($join ? 's.nom' : 'NULL');
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::select("SELECT t.rowid id, {$c['ref']} ref, {$empresa} empresa, {$c['titulo']} titulo FROM {$this->p}{$c['tabla']} t{$join} WHERE t.rowid IN ({$in})", array_values($ids)) as $r) {
                $out["{$tipo}:{$r->id}"] = $this->itemElemento($tipo, (int) $r->id, (string) $r->ref, $r->empresa, $r->titulo);
            }
        }

        return $out;
    }

    // ---------------------------------------------------------- creación

    /**
     * @param  array{titulo: string, fecha?: ?string, hora?: ?string, prioridad?: ?int, recurrencia?: ?int, tipo?: ?string, ref?: ?int, descripcion?: ?string, integrantes?: array, categoria?: ?int, state_change?: ?int}  $d
     * @return array{id: int, programada: string, integrantes: array<int>}
     */
    public function crear(object $usuario, array $d): array
    {
        $uid = (int) $usuario->getKey();
        $fecha = ($d['fecha'] ?? null) ?: now(self::TZ)->toDateString();
        $hora = ($d['hora'] ?? null) ?: '08:00';
        $datep = Carbon::createFromFormat('Y-m-d H:i', "{$fecha} {$hora}", self::TZ)->format('Y-m-d H:i:s');

        $tipo = ($d['tipo'] ?? null) ?: '';
        $ref = $tipo !== '' && ! empty($d['ref']) ? (string) (int) $d['ref'] : '';
        if ($ref === '') {
            $tipo = '';
        }
        $desc = trim((string) ($d['descripcion'] ?? ''));
        $html = $desc === '' ? '' : '<p>'.str_replace(["\r\n", "\n", "\r"], '<br>', e($desc)).'</p>';

        $validos = array_map('intval', array_column(DB::select("SELECT rowid FROM {$this->p}user WHERE statut = 1"), 'rowid'));
        // Quien crea siempre participa; a los demás se les exige ser usuarios activos.
        $otros = array_filter(array_map('intval', (array) ($d['integrantes'] ?? [])), fn ($i) => $i !== $uid && in_array($i, $validos, true));
        $integrantes = array_values(array_unique(array_merge([$uid], $otros)));

        $id = DB::transaction(function () use ($uid, $d, $datep, $tipo, $ref, $html, $integrantes) {
            $id = DB::table('solubitacora_tareas')->insertGetId([
                'date_creation' => now(self::TZ), 'date_schedule' => $datep, 'fk_user_creat' => $uid, 'fk_user_modif' => $uid, 'tms' => now(),
                'title' => mb_substr(trim($d['titulo']), 0, 250), 'description' => self::codificar($html),
                'fk_c_solubitacora_prioridad' => ($d['prioridad'] ?? null) ?: 3, 'fk_c_solubitacora_categoria' => ($d['categoria'] ?? null) ?: null,
                'fk_c_solubitacora_estado' => self::ACTIVA, 'type_rq' => $tipo, 'ref_rq' => $ref,
                'state_change' => (int) ($d['state_change'] ?? 0), 'recurrencia' => ($d['recurrencia'] ?? null) ?: 1, 'target_type' => 3,
            ]);
            DB::table('solubitacora_seguimiento')->insert(array_map(fn ($u) => [
                'fk_solubitacora_tareas' => $id, 'fk_user' => $u, 'annotations' => '', 'assigned_date' => $datep, 'fk_c_solubitacora_seguimiento_estado' => 0,
            ], $integrantes));

            return $id;
        });

        return ['id' => $id, 'programada' => $datep, 'integrantes' => $integrantes];
    }

    // ---------------------------------------------------------- lectura

    private function filaSql(): string
    {
        return "SELECT t.rowid id, t.title, t.description, t.date_schedule, t.date_close, t.fk_c_solubitacora_prioridad pr, t.type_rq, t.ref_rq, t.recurrencia rec,
                t.state_change, t.fk_user_creat, t.fk_c_solubitacora_estado estado,
                (SELECT TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.lastname,''))) FROM {$this->p}user u WHERE u.rowid = t.fk_user_creat) creador_nombre,
                (SELECT COUNT(*) FROM {$this->p}solubitacora_seguimiento x WHERE x.fk_solubitacora_tareas = t.rowid) n
            FROM {$this->p}solubitacora_tareas t";
    }

    private const ES_INTEGRANTE = 'EXISTS (SELECT 1 FROM %ssolubitacora_seguimiento s WHERE s.fk_solubitacora_tareas = t.rowid AND s.fk_user = ?)';

    private const PENDIENTE = 't.date_close IS NULL AND t.fk_c_solubitacora_estado <> 38';

    /** Próxima fecha en que le toca a una tarea (las recurrentes se recalculan; no generan filas). */
    public static function proxima(Carbon $prog, int $rec, Carbon $hoy): Carbon
    {
        if ($rec <= 1 || $prog->gte($hoy)) {
            return $prog;
        }
        $base = $hoy->copy()->setTime($prog->hour, $prog->minute);

        return match ($rec) {
            2 => $base,
            3 => $base->copy()->addDays(($prog->dayOfWeekIso - $hoy->dayOfWeekIso + 7) % 7),
            4 => (function () use ($prog, $hoy, $base) {
                $c = $base->copy()->day(min($prog->day, $base->daysInMonth));
                if ($c->lt($hoy)) {
                    $m = $hoy->copy()->startOfMonth()->addMonthNoOverflow();
                    $c = $m->setTime($prog->hour, $prog->minute)->day(min($prog->day, $m->daysInMonth));
                }

                return $c;
            })(),
            default => $prog,
        };
    }

    /**
     * Tareas del usuario como items del programador.
     *
     * @return array<int, array>
     */
    public function items(object $usuario, string $estado = 'pendientes', int $limite = 300): array
    {
        $pend = $estado !== 'realizados';
        $filtro = $pend ? self::PENDIENTE : '(t.date_close IS NOT NULL OR t.fk_c_solubitacora_estado = 38)';
        $orden = $pend ? 't.date_schedule ASC' : 't.date_close DESC';
        $filas = DB::select($this->filaSql().' WHERE '.sprintf(self::ES_INTEGRANTE, $this->p)." AND {$filtro} ORDER BY {$orden} LIMIT {$limite}", [$usuario->getKey()]);

        return $this->aItems($filas, $pend, $usuario);
    }

    private function aItems(array $filas, bool $pend, object $usuario): array
    {
        $uid = (int) $usuario->getKey();
        $ahora = Carbon::now(self::TZ)->startOfMinute();
        $hoy = $ahora->copy()->startOfDay();
        $el = $this->elementos(array_map(fn ($f) => [(string) $f->type_rq, (int) $f->ref_rq], $filas));
        $prio = collect($this->catalogos()['prioridades'])->keyBy('id');
        $prioPorId = collect(DB::select("SELECT rowid, label, color, fontColor FROM {$this->p}c_solubitacora_prioridad"))->keyBy('rowid');

        return array_map(function ($f) use ($pend, $ahora, $hoy, $el, $prioPorId, $uid, $usuario) {
            $prog = Carbon::parse($f->date_schedule, self::TZ);
            $cuando = $pend ? self::proxima($prog, (int) $f->rec, $hoy) : Carbon::parse($f->date_close ?: $f->date_schedule, self::TZ);
            $e = $el["{$f->type_rq}:{$f->ref_rq}"] ?? null;
            $p = $prioPorId[$f->pr] ?? null;

            return [
                'id' => (int) $f->id, 'origen' => 'tarea', 'tarea' => (int) $f->id,
                'modulo' => $e['modulo'] ?? null, 'moduloNombre' => $e['tipoNombre'] ?? 'Tarea', 'card' => $e['card'] ?? 4, 'icono' => $e['icono'] ?? 'fa-list-check',
                'fkRecord' => $e ? $e['id'] : 0, 'ref' => $e['ref'] ?? '', 'empresa' => $e ? ($e['empresa'] ?: $e['titulo']) : 'Tarea libre',
                'titulo' => $f->title, 'cuando' => $cuando->format('Y-m-d H:i'),
                'grupo' => ! $pend ? 'realizado' : ($cuando->lt($hoy) ? 'atrasado' : ($cuando->lt($hoy->copy()->addDay()) ? 'hoy' : 'proximo')),
                'horaVencida' => $pend && $cuando->lt($ahora),
                'observacion' => mb_substr(self::textoPlano($f->description), 0, 300), 'actividad' => '',
                'prioridad' => $p ? ['label' => $p->label, 'color' => $p->color] : null,
                'recurrencia' => (int) $f->rec > 1 ? self::RECURRENCIAS[(int) $f->rec] : null,
                'integrantes' => (int) $f->n,
                // Autoría: lo creado por otra persona se muestra distinto y puede ser de solo lectura.
                'propia' => (int) $f->fk_user_creat === $uid, 'creadorId' => (int) $f->fk_user_creat, 'creador' => trim((string) $f->creador_nombre),
                'puedeCambiar' => $usuario->isAdmin() || (int) $f->fk_user_creat === $uid || (int) $f->state_change === 1,
                'puedeEliminar' => $usuario->isAdmin() || (int) $f->fk_user_creat === $uid,
                'puedeSalir' => (int) $f->fk_user_creat !== $uid,
            ];
        }, $filas);
    }

    /** Conteos para los chips del programador. */
    public function resumen(object $usuario): array
    {
        $it = $this->items($usuario, 'pendientes');
        $c = fn ($g) => count(array_filter($it, fn ($i) => $i['grupo'] === $g));
        $real = DB::selectOne("SELECT COUNT(*) n FROM {$this->p}solubitacora_tareas t WHERE ".sprintf(self::ES_INTEGRANTE, $this->p).' AND (t.date_close IS NOT NULL OR t.fk_c_solubitacora_estado = 38)', [$usuario->getKey()]);

        return ['atrasados' => $c('atrasado'), 'hoy' => $c('hoy'), 'proximos' => $c('proximo'), 'realizados' => (int) $real->n];
    }

    /** Detalle de una tarea para el panel lateral; null si el usuario no participa (y no es admin). */
    public function detalle(object $usuario, int $id): ?array
    {
        $f = DB::selectOne($this->filaSql().' WHERE t.rowid = ?', [$id]);
        if (! $f) {
            return null;
        }
        $uid = (int) $usuario->getKey();
        $ints = DB::select("SELECT s.fk_user id, TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.lastname,''))) nombre, u.login FROM {$this->p}solubitacora_seguimiento s JOIN {$this->p}user u ON u.rowid = s.fk_user WHERE s.fk_solubitacora_tareas = ?", [$id]);
        $esIntegrante = (bool) array_filter($ints, fn ($i) => (int) $i->id === $uid);
        if (! $esIntegrante && ! $usuario->isAdmin() && (int) $f->fk_user_creat !== $uid) {
            return null;
        }
        $item = $this->aItems([$f], $f->date_close === null && (int) $f->estado !== self::CERRADA, $usuario)[0];
        $creador = DB::selectOne("SELECT TRIM(CONCAT(COALESCE(firstname,''),' ',COALESCE(lastname,''))) n FROM {$this->p}user WHERE rowid = ?", [$f->fk_user_creat]);
        $el = $this->elementos([[(string) $f->type_rq, (int) $f->ref_rq]])["{$f->type_rq}:{$f->ref_rq}"] ?? null;
        $vinculo = DB::table('panel_ms_vinculos')->where('tarea_id', $id)->where('user_id', $uid)->first();

        return $item + [
            'descripcion' => self::textoPlano($f->description),
            'programada' => Carbon::parse($f->date_schedule, self::TZ)->format('Y-m-d H:i'),
            'cerrada' => $f->date_close !== null || (int) $f->estado === self::CERRADA,
            'cerradaEn' => $f->date_close,
            'creador' => $creador->n ?? '', 'creadorId' => (int) $f->fk_user_creat,
            'integrantesLista' => array_map(fn ($i) => ['id' => (int) $i->id, 'nombre' => trim($i->nombre) ?: $i->login], $ints),
            'elemento' => $el,
            'puedeEliminar' => $usuario->isAdmin() || (int) $f->fk_user_creat === $uid,
            'puedeCambiar' => $usuario->isAdmin() || (int) $f->fk_user_creat === $uid || ((int) $f->state_change === 1 && $esIntegrante),
            'soloCreador' => (int) $f->state_change === 0,
            'teams' => $vinculo->teams_url ?? null,
            'enOutlook' => (bool) ($vinculo->ms_evento_id ?? false),
        ];
    }

    // ---------------------------------------------------------- cambios de estado

    private function paraCambiar(object $usuario, int $id): object
    {
        $d = $this->detalle($usuario, $id);
        if (! $d || ! $d['puedeCambiar']) {
            throw new InvalidArgumentException($d && $d['soloCreador'] ? 'Solo quien creó la tarea puede cambiarla.' : 'Tarea no encontrada.');
        }

        return (object) $d;
    }

    public function cerrar(object $usuario, int $id, bool $cerrar = true): void
    {
        $this->paraCambiar($usuario, $id);
        DB::table('solubitacora_tareas')->where('rowid', $id)->update($cerrar
            ? ['date_close' => now(self::TZ), 'fk_c_solubitacora_estado' => self::CERRADA, 'fk_user_modif' => $usuario->getKey(), 'tms' => now()]
            : ['date_close' => null, 'fk_c_solubitacora_estado' => self::ACTIVA, 'fk_user_modif' => $usuario->getKey(), 'tms' => now()]);
    }

    /** Elimina la tarea y sus integrantes. Solo quien la creó o un administrador. */
    public function eliminar(object $usuario, int $id): void
    {
        $d = $this->detalle($usuario, $id);
        if (! $d || ! $d['puedeEliminar']) {
            throw new InvalidArgumentException($d ? 'Solo quien creó la tarea puede eliminarla.' : 'Tarea no encontrada.');
        }
        DB::transaction(function () use ($id) {
            DB::table('solubitacora_seguimiento')->where('fk_solubitacora_tareas', $id)->delete();
            DB::table('panel_ms_vinculos')->where('tarea_id', $id)->delete();
            DB::table('solubitacora_tareas')->where('rowid', $id)->delete();
        });
    }

    /** «Rechazar» una tarea creada por otra persona: el usuario deja de ser integrante (la tarea sigue para los demás). */
    public function salir(object $usuario, int $id): void
    {
        $d = $this->detalle($usuario, $id);
        if (! $d) {
            throw new InvalidArgumentException('Tarea no encontrada.');
        }
        if (! $d['puedeSalir']) {
            throw new InvalidArgumentException('Quien creó la tarea no puede rechazarla: puede eliminarla.');
        }
        $uid = (int) $usuario->getKey();
        DB::transaction(function () use ($id, $uid) {
            DB::table('solubitacora_seguimiento')->where('fk_solubitacora_tareas', $id)->where('fk_user', $uid)->delete();
            DB::table('panel_ms_vinculos')->where('tarea_id', $id)->where('user_id', $uid)->delete();
        });
    }

    /** @return string nueva fecha-hora programada (Y-m-d H:i:s) */
    public function posponer(object $usuario, int $id, string $fecha, ?string $hora): string
    {
        $d = $this->paraCambiar($usuario, $id);
        $hora = $hora ?: substr($d->programada, 11, 5);
        $datep = Carbon::createFromFormat('Y-m-d H:i', "{$fecha} {$hora}", self::TZ)->format('Y-m-d H:i:s');
        DB::table('solubitacora_tareas')->where('rowid', $id)->update(['date_schedule' => $datep, 'fk_user_modif' => $usuario->getKey(), 'tms' => now()]);

        return $datep;
    }

    /** Usuarios con sesión de Office 365 en una tarea (para sincronizar a cada integrante). */
    public function integrantes(int $id): array
    {
        return array_map('intval', array_column(DB::select("SELECT fk_user FROM {$this->p}solubitacora_seguimiento WHERE fk_solubitacora_tareas = ?", [$id]), 'fk_user'));
    }
}
