<?php

namespace App\Services\Microsoft;

use App\Models\DolibarrUser;
use App\Services\Panel\ActividadesService;
use App\Services\Panel\TareasService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza los recordatorios del programador (actioncomm) con Office 365 del usuario:
 *   - Calendario de Outlook (evento con aviso y, opcionalmente, reunión de Teams).
 *   - Microsoft To Do (tarea con vencimiento).
 * Sentido panel -> Microsoft al crear / cerrar / reabrir, y Microsoft -> panel al reprogramar o completar allá
 * (webhook de Graph + comando programado microsoft:sincronizar).
 */
class MicrosoftSyncService
{
    private const DURACION_MIN = 30;

    private const LISTA_TAREAS = 'Industry · Seguimientos';

    public function __construct(private MicrosoftGraphService $graph)
    {
    }

    public function cuenta(int $userId): ?object
    {
        return DB::table('panel_ms_cuentas')->where('user_id', $userId)->where('estado', 'activa')->first();
    }

    /** Estado para la tarjeta del programador. */
    public function estado(int $userId): array
    {
        $c = DB::table('panel_ms_cuentas')->where('user_id', $userId)->first();

        return [
            'configurado' => $this->graph->configurado(),
            'conectado' => (bool) $c && $c->estado === 'activa',
            'reconectar' => (bool) $c && $c->estado !== 'activa',
            'email' => $c->ms_email ?? null,
            'calendario' => (bool) ($c->usar_calendario ?? true),
            'tareas' => (bool) ($c->usar_tareas ?? true),
            'teams' => (bool) ($c->usar_teams ?? false),
            'ultimaSync' => $c->ultima_sync ?? null,
        ];
    }

    // ------------------------------------------------------------------ panel -> Microsoft

    /** Llamar tras crear un recordatorio. Nunca lanza: una caída de Microsoft no debe romper el guardado. */
    public function empujarCreado(int $userId, int $eventoId, ?bool $teams = null): array
    {
        $cuenta = $this->cuenta($userId);
        if (! $cuenta) {
            return [];
        }
        $r = ['calendario' => false, 'tarea' => false, 'teams' => null, 'error' => null];

        try {
            $ev = $this->evento($eventoId);
            if (! $ev) {
                return $r;
            }
            $x = $this->publicar($cuenta, $ev, $teams ?? (bool) $cuenta->usar_teams, 1);
            DB::table('panel_ms_vinculos')->updateOrInsert(['actioncomm_id' => $eventoId], ['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()] + $x['vinculo']);
            $r = $x['r'] + ['error' => null];
            $this->asegurarSuscripcion($cuenta);
        } catch (Throwable $e) {
            Log::warning('Microsoft: no se pudo crear el recordatorio '.$eventoId.' — '.$e->getMessage());
            $r['error'] = 'No se pudo sincronizar con Office 365.';
        }

        return $r;
    }

    /**
     * Crea el evento de Outlook, la tarea de To Do y (opcional) la reunión de Teams de un registro ($ev: label, datep, ref, empresa, note).
     *
     * @return array{r: array, vinculo: array}
     */
    private function publicar(object $cuenta, object $ev, bool $teams, int $recurrencia): array
    {
        $r = ['calendario' => false, 'tarea' => false, 'teams' => null];
        $inicio = Carbon::parse($ev->datep, ActividadesService::TZ);
        $http = $this->graph->http($cuenta);
        $vinculo = ['huella' => $this->huella($ev->label, $inicio)];

        if ($cuenta->usar_calendario) {
            $payload = [
                'subject' => $ev->label,
                'body' => ['contentType' => 'HTML', 'content' => $this->cuerpo($ev)],
                'start' => $this->fecha($inicio),
                'end' => $this->fecha($inicio->copy()->addMinutes(self::DURACION_MIN)),
                'isReminderOn' => true,
                'reminderMinutesBeforeStart' => 15,
                'categories' => ['Industry'],
            ];
            if ($rec = $this->recurrencia($inicio, $recurrencia)) {
                $payload['recurrence'] = $rec;
            }
            if ($teams) {
                $payload += ['isOnlineMeeting' => true, 'onlineMeetingProvider' => 'teamsForBusiness'];
            }
            $x = $this->graph->revisar($http->post('/me/events', $payload))->json();
            $vinculo['ms_evento_id'] = $x['id'] ?? null;
            $vinculo['teams_url'] = $x['onlineMeeting']['joinUrl'] ?? null;
            $r['calendario'] = true;
            $r['teams'] = $vinculo['teams_url'];
        }

        if ($cuenta->usar_tareas) {
            $lista = $this->listaTareas($cuenta, $http);
            $x = $this->graph->revisar($http->post("/me/todo/lists/{$lista}/tasks", [
                'title' => $ev->label,
                'body' => ['contentType' => 'text', 'content' => trim(trim($ev->ref.' · '.$ev->empresa, ' ·')."\n".$this->observacion($ev))],
                'dueDateTime' => $this->fecha($inicio),
                'isReminderOn' => true,
                'reminderDateTime' => $this->fecha($inicio),
                'categories' => ['Industry'],
            ]))->json();
            $vinculo['ms_tarea_id'] = $x['id'] ?? null;
            $r['tarea'] = true;
        }

        return ['r' => $r, 'vinculo' => $vinculo];
    }

    private function recurrencia(Carbon $inicio, int $rec): ?array
    {
        $patron = match ($rec) {
            2 => ['type' => 'daily', 'interval' => 1],
            3 => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => [strtolower($inicio->englishDayOfWeek)], 'firstDayOfWeek' => 'monday'],
            4 => ['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => $inicio->day],
            default => null,
        };

        return $patron ? ['pattern' => $patron, 'range' => ['type' => 'noEnd', 'startDate' => $inicio->toDateString()]] : null;
    }

    // ------------------------------------------------------------------ tareas (solubitacora_tareas)

    /** Datos de una tarea con la forma que espera publicar(). */
    private function tarea(int $id): ?object
    {
        $t = DB::table('solubitacora_tareas')->where('rowid', $id)->first();
        if (! $t) {
            return null;
        }
        $el = app(TareasService::class)->elementos([[(string) $t->type_rq, (int) $t->ref_rq]])["{$t->type_rq}:{$t->ref_rq}"] ?? null;

        return (object) ['label' => $t->title, 'datep' => $t->date_schedule, 'note' => TareasService::textoPlano($t->description), 'ref' => $el['ref'] ?? '', 'empresa' => $el ? ($el['empresa'] ?: $el['titulo']) : '', 'rec' => (int) $t->recurrencia];
    }

    /** Tras crear una tarea: evento / tarea de To Do / Teams en la cuenta de cada integrante que haya conectado Office 365. */
    public function empujarTarea(int $tareaId, int $creadorId, ?bool $teams = null): array
    {
        $ev = $this->tarea($tareaId);
        $r = ['calendario' => false, 'tarea' => false, 'teams' => null];
        if (! $ev) {
            return $r;
        }

        foreach (app(TareasService::class)->integrantes($tareaId) as $uid) {
            $cuenta = $this->cuenta($uid);
            if (! $cuenta) {
                continue;
            }
            try {
                $x = $this->publicar($cuenta, $ev, $uid === $creadorId ? ($teams ?? (bool) $cuenta->usar_teams) : false, $ev->rec);
                DB::table('panel_ms_vinculos')->updateOrInsert(['tarea_id' => $tareaId, 'user_id' => $uid], ['created_at' => now(), 'updated_at' => now()] + $x['vinculo']);
                if ($uid === $creadorId) {
                    $r = $x['r'];
                }
                $this->asegurarSuscripcion($cuenta);
            } catch (Throwable $e) {
                Log::warning("Microsoft: no se pudo enviar la tarea {$tareaId} al usuario {$uid} — ".$e->getMessage());
                if ($uid === $creadorId) {
                    $r['error'] = 'No se pudo sincronizar con Office 365.';
                }
            }
        }

        return $r;
    }

    /** Tarea cerrada / reabierta: se marca en el evento y la tarea de To Do de cada integrante. */
    public function empujarEstadoTarea(int $tareaId, bool $cerrada, ?int $excepto = null): void
    {
        $ev = $this->tarea($tareaId);
        foreach (DB::table('panel_ms_vinculos')->where('tarea_id', $tareaId)->get() as $v) {
            $cuenta = (int) $v->user_id !== $excepto ? $this->cuenta((int) $v->user_id) : null;
            if (! $cuenta || ! $ev) {
                continue;
            }
            try {
                $http = $this->graph->http($cuenta);
                if ($v->ms_evento_id) {
                    $this->graph->revisar($http->patch("/me/events/{$v->ms_evento_id}", ['subject' => ($cerrada ? '✔ ' : '').$ev->label]));
                }
                if ($v->ms_tarea_id && $cuenta->lista_tareas_id) {
                    $this->graph->revisar($http->patch("/me/todo/lists/{$cuenta->lista_tareas_id}/tasks/{$v->ms_tarea_id}", ['status' => $cerrada ? 'completed' : 'notStarted']));
                }
            } catch (Throwable $e) {
                Log::warning("Microsoft: no se pudo actualizar el estado de la tarea {$tareaId} — ".$e->getMessage());
            }
        }
    }

    /** Tarea eliminada: se borra el evento de Outlook y la tarea de To Do de cada integrante. Llamar ANTES de borrar los vínculos. */
    public function eliminarTarea(int $tareaId): void
    {
        foreach (DB::table('panel_ms_vinculos')->where('tarea_id', $tareaId)->get() as $v) {
            $cuenta = $this->cuenta((int) $v->user_id);
            if (! $cuenta) {
                continue;
            }
            try {
                $http = $this->graph->http($cuenta);
                if ($v->ms_evento_id) {
                    $http->delete("/me/events/{$v->ms_evento_id}");
                }
                if ($v->ms_tarea_id && $cuenta->lista_tareas_id) {
                    $http->delete("/me/todo/lists/{$cuenta->lista_tareas_id}/tasks/{$v->ms_tarea_id}");
                }
            } catch (Throwable $e) {
                Log::warning("Microsoft: no se pudo borrar la tarea {$tareaId} del usuario {$v->user_id} — ".$e->getMessage());
            }
        }
    }

    /** Tarea pospuesta: se mueve el evento y el vencimiento en Office 365 de cada integrante. */
    public function reprogramarTarea(int $tareaId, ?int $excepto = null): void
    {
        $ev = $this->tarea($tareaId);
        if (! $ev) {
            return;
        }
        $inicio = Carbon::parse($ev->datep, ActividadesService::TZ);
        foreach (DB::table('panel_ms_vinculos')->where('tarea_id', $tareaId)->get() as $v) {
            $cuenta = (int) $v->user_id !== $excepto ? $this->cuenta((int) $v->user_id) : null;
            if (! $cuenta) {
                continue;
            }
            try {
                $http = $this->graph->http($cuenta);
                if ($v->ms_evento_id) {
                    $this->graph->revisar($http->patch("/me/events/{$v->ms_evento_id}", ['start' => $this->fecha($inicio), 'end' => $this->fecha($inicio->copy()->addMinutes(self::DURACION_MIN))]));
                }
                if ($v->ms_tarea_id && $cuenta->lista_tareas_id) {
                    $this->graph->revisar($http->patch("/me/todo/lists/{$cuenta->lista_tareas_id}/tasks/{$v->ms_tarea_id}", ['dueDateTime' => $this->fecha($inicio), 'reminderDateTime' => $this->fecha($inicio)]));
                }
                DB::table('panel_ms_vinculos')->where('id', $v->id)->update(['huella' => $this->huella($ev->label, $inicio), 'updated_at' => now()]);
            } catch (Throwable $e) {
                Log::warning("Microsoft: no se pudo reprogramar la tarea {$tareaId} — ".$e->getMessage());
            }
        }
    }

    /** Cambios hechos en Outlook / To Do sobre las tareas del usuario: se reflejan en el panel. */
    private function sincronizarTareas(object $cuenta, object $usuario, array &$res): void
    {
        $p = DB::getTablePrefix();
        $filas = DB::select("SELECT v.*, t.title label, t.date_schedule datep FROM {$p}panel_ms_vinculos v
            JOIN {$p}solubitacora_tareas t ON t.rowid = v.tarea_id
            WHERE v.user_id = ? AND t.date_close IS NULL AND t.fk_c_solubitacora_estado <> 38 ORDER BY t.date_schedule LIMIT 80", [$cuenta->user_id]);
        $http = $this->graph->http($cuenta);
        $tareas = app(TareasService::class);

        foreach ($filas as $v) {
            try {
                if ($v->ms_tarea_id && $cuenta->lista_tareas_id) {
                    $t = $http->get("/me/todo/lists/{$cuenta->lista_tareas_id}/tasks/{$v->ms_tarea_id}?\$select=status");
                    if ($t->status() === 404) {
                        DB::table('panel_ms_vinculos')->where('id', $v->id)->update(['ms_tarea_id' => null]);
                    } elseif ($t->successful() && $t->json('status') === 'completed') {
                        try {
                            $tareas->cerrar($usuario, (int) $v->tarea_id);
                            $this->empujarEstadoTarea((int) $v->tarea_id, true, (int) $cuenta->user_id);
                            $res['completados']++;

                            continue;
                        } catch (\InvalidArgumentException) {
                            // Sin permiso para cerrar la tarea (state_change): se deja como está.
                        }
                    }
                }
                if ($v->ms_evento_id) {
                    $e = $http->get("/me/events/{$v->ms_evento_id}?\$select=subject,start,isCancelled");
                    if ($e->status() === 404 || $e->json('isCancelled')) {
                        DB::table('panel_ms_vinculos')->where('id', $v->id)->update(['ms_evento_id' => null, 'teams_url' => null]);
                        $res['desvinculados']++;
                    } elseif ($e->successful()) {
                        $asunto = trim((string) $e->json('subject'));
                        $inicio = Carbon::createFromFormat('Y-m-d\TH:i:s', substr((string) $e->json('start.dateTime'), 0, 19), ActividadesService::TZ);
                        if ($asunto !== '' && $this->huella($asunto, $inicio) !== $v->huella) {
                            try {
                                $tareas->posponer($usuario, (int) $v->tarea_id, $inicio->toDateString(), $inicio->format('H:i'));
                                DB::table('solubitacora_tareas')->where('rowid', $v->tarea_id)->update(['title' => mb_substr($asunto, 0, 250)]);
                                DB::table('panel_ms_vinculos')->where('id', $v->id)->update(['huella' => $this->huella($asunto, $inicio), 'updated_at' => now()]);
                                $this->reprogramarTarea((int) $v->tarea_id, (int) $cuenta->user_id);
                                $res['reprogramados']++;
                            } catch (\InvalidArgumentException) {
                                // Sin permiso para cambiar la tarea.
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Microsoft: fallo sincronizando la tarea {$v->tarea_id} — ".$e->getMessage());
            }
        }
    }

    /** Llamar tras marcar un recordatorio como realizado o reabrirlo. */
    public function empujarEstado(int $eventoId, bool $realizado): void
    {
        $v = DB::table('panel_ms_vinculos')->where('actioncomm_id', $eventoId)->first();
        $cuenta = $v ? $this->cuenta((int) $v->user_id) : null;
        $ev = $v ? $this->evento($eventoId) : null;
        if (! $cuenta || ! $ev) {
            return;
        }

        try {
            $http = $this->graph->http($cuenta);
            if ($v->ms_evento_id) {
                $asunto = ($realizado ? '✔ ' : '').$ev->label;
                $this->graph->revisar($http->patch("/me/events/{$v->ms_evento_id}", ['subject' => $asunto]));
            }
            if ($v->ms_tarea_id && $cuenta->lista_tareas_id) {
                $this->graph->revisar($http->patch("/me/todo/lists/{$cuenta->lista_tareas_id}/tasks/{$v->ms_tarea_id}", ['status' => $realizado ? 'completed' : 'notStarted']));
            }
        } catch (Throwable $e) {
            Log::warning('Microsoft: no se pudo actualizar el estado del recordatorio '.$eventoId.' — '.$e->getMessage());
        }
    }

    /** Envía a Office 365 los recordatorios pendientes que aún no tienen evento/tarea (los de antes de conectar). */
    public function enviarPendientes(object $cuenta, int $limite = 30): int
    {
        $p = DB::getTablePrefix();
        $ids = DB::select("SELECT a.id FROM {$p}actioncomm a
            LEFT JOIN {$p}panel_ms_vinculos v ON v.actioncomm_id = a.id
            WHERE v.id IS NULL AND a.code LIKE ? AND a.elementtype IN ('solicitud','clientecotizacion','orden')
              AND (a.fk_user_author = ? OR a.fk_user_action = ?) AND a.percent < 100 AND a.datep >= ?
            ORDER BY a.datep LIMIT {$limite}", ['AC\_SEGUIMIENTO\_RECORDATORIO%', $cuenta->user_id, $cuenta->user_id, now(ActividadesService::TZ)->subDays(1)->startOfDay()->format('Y-m-d H:i:s')]);

        $n = 0;
        foreach ($ids as $f) {
            $r = $this->empujarCreado((int) $cuenta->user_id, (int) $f->id, false);
            if (! empty($r['calendario']) || ! empty($r['tarea'])) {
                $n++;
            }
        }

        return $n;
    }

    /** Eventos del calendario de Outlook del usuario en un rango (sin los que nacieron en el panel). */
    public function eventosOutlook(object $cuenta, Carbon $desde, Carbon $hasta): array
    {
        $propios = DB::table('panel_ms_vinculos')->where('user_id', $cuenta->user_id)->whereNotNull('ms_evento_id')->pluck('ms_evento_id')->flip();
        $r = $this->graph->revisar($this->graph->http($cuenta)->get('/me/calendarView', [
            'startDateTime' => $desde->copy()->startOfDay()->format('Y-m-d\TH:i:sP'),
            'endDateTime' => $hasta->copy()->endOfDay()->format('Y-m-d\TH:i:sP'),
            '$select' => 'id,subject,start,end,isAllDay,isCancelled,webLink,onlineMeeting,showAs,organizer',
            '$orderby' => 'start/dateTime',
            '$top' => 250,
        ]));

        return collect($r->json('value', []))
            ->reject(fn ($e) => ($e['isCancelled'] ?? false) || isset($propios[$e['id']]))
            ->map(fn ($e) => [
                'id' => $e['id'],
                'titulo' => trim((string) ($e['subject'] ?? '')) ?: '(Sin asunto)',
                'cuando' => str_replace('T', ' ', substr($e['start']['dateTime'], 0, 16)),
                'fin' => str_replace('T', ' ', substr($e['end']['dateTime'], 0, 16)),
                'todoDia' => (bool) ($e['isAllDay'] ?? false),
                'url' => $e['webLink'] ?? null,
                'teams' => $e['onlineMeeting']['joinUrl'] ?? null,
                'organizador' => $e['organizer']['emailAddress']['name'] ?? null,
            ])->values()->all();
    }

    // ------------------------------------------------------------------ Microsoft -> panel

    /** Trae los cambios hechos en Outlook / To Do sobre los recordatorios pendientes de la cuenta. */
    public function sincronizarCuenta(object $cuenta): array
    {
        $res = ['reprogramados' => 0, 'completados' => 0, 'desvinculados' => 0, 'enviados' => 0];
        $usuario = DolibarrUser::find($cuenta->user_id);
        if (! $usuario) {
            return $res;
        }

        try {
            $res['enviados'] = $this->enviarPendientes($cuenta);
        } catch (Throwable $e) {
            Log::warning('Microsoft: no se pudieron enviar los pendientes — '.$e->getMessage());
        }
        $cuenta = DB::table('panel_ms_cuentas')->where('id', $cuenta->id)->first() ?? $cuenta;

        $filas = DB::select('SELECT v.*, a.label, a.datep, a.elementtype FROM '.DB::getTablePrefix().'panel_ms_vinculos v
            JOIN '.DB::getTablePrefix().'actioncomm a ON a.id = v.actioncomm_id
            WHERE v.user_id = ? AND a.percent < 100 ORDER BY a.datep LIMIT 80', [$cuenta->user_id]);

        $http = $this->graph->http($cuenta);

        foreach ($filas as $v) {
            try {
                if ($v->ms_tarea_id && $cuenta->lista_tareas_id) {
                    $t = $http->get("/me/todo/lists/{$cuenta->lista_tareas_id}/tasks/{$v->ms_tarea_id}?\$select=status");
                    if ($t->status() === 404) {
                        DB::table('panel_ms_vinculos')->where('id', $v->id)->update(['ms_tarea_id' => null]);
                    } elseif ($t->successful() && $t->json('status') === 'completed') {
                        app(ActividadesService::class)->cambiarEstado($usuario, (int) $v->actioncomm_id, 'REALIZADO', 'Completada desde Microsoft To Do.');
                        $this->empujarEstado((int) $v->actioncomm_id, true);
                        $res['completados']++;

                        continue;
                    }
                }

                if ($v->ms_evento_id) {
                    $e = $http->get("/me/events/{$v->ms_evento_id}?\$select=subject,start,isCancelled");
                    if ($e->status() === 404 || $e->json('isCancelled')) {
                        DB::table('panel_ms_vinculos')->where('id', $v->id)->update(['ms_evento_id' => null, 'teams_url' => null]);
                        $res['desvinculados']++;
                    } elseif ($e->successful()) {
                        $asunto = trim((string) $e->json('subject'));
                        $inicio = Carbon::createFromFormat('Y-m-d\TH:i:s', substr((string) $e->json('start.dateTime'), 0, 19), ActividadesService::TZ);
                        if ($asunto !== '' && $this->huella($asunto, $inicio) !== $v->huella) {
                            $this->aplicarCambio($v, mb_substr($asunto, 0, 250), $inicio);
                            $res['reprogramados']++;
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Microsoft: fallo sincronizando el recordatorio '.$v->actioncomm_id.' — '.$e->getMessage());
            }
        }

        $this->sincronizarTareas($cuenta, $usuario, $res);

        DB::table('panel_ms_cuentas')->where('id', $cuenta->id)->update(['ultima_sync' => now(), 'updated_at' => now()]);

        return $res;
    }

    private function aplicarCambio(object $v, string $asunto, Carbon $inicio): void
    {
        $datep = $inicio->format('Y-m-d H:i:s');
        DB::table('actioncomm')->where('id', $v->actioncomm_id)->update(['label' => $asunto, 'datep' => $datep, 'datep2' => $datep, 'tms' => now()]);
        foreach (ActividadesService::MODULOS as $m) {
            if ($m['elemento'] === $v->elementtype && $m['proximo']) {
                $fk = DB::table('actioncomm')->where('id', $v->actioncomm_id)->value('fk_element');
                DB::table($m['obj'])->where('rowid', $fk)->update(['fecha_proximo_contacto' => $datep]);
            }
        }
        DB::table('panel_ms_vinculos')->where('id', $v->id)->update(['huella' => $this->huella($asunto, $inicio), 'updated_at' => now()]);
        // La tarea de To Do sigue la nueva fecha.
        $cuenta = $this->cuenta((int) $v->user_id);
        if ($cuenta && $v->ms_tarea_id && $cuenta->lista_tareas_id) {
            try {
                $this->graph->http($cuenta)->patch("/me/todo/lists/{$cuenta->lista_tareas_id}/tasks/{$v->ms_tarea_id}", ['title' => $asunto, 'dueDateTime' => $this->fecha($inicio), 'reminderDateTime' => $this->fecha($inicio)]);
            } catch (Throwable) {
            }
        }
    }

    // ------------------------------------------------------------------ suscripción (webhook)

    public function clientState(object $cuenta): string
    {
        return substr(hash_hmac('sha256', 'ms-'.$cuenta->id, (string) config('app.key')), 0, 40);
    }

    /** Crea o renueva la suscripción a cambios del calendario (vence cada ~3 días). No hace nada sin MS_WEBHOOK_URL. */
    public function asegurarSuscripcion(object $cuenta): void
    {
        $url = config('services.microsoft.webhook_url');
        if (! $url || ! $cuenta->usar_calendario) {
            return;
        }
        if ($cuenta->suscripcion_id && $cuenta->suscripcion_expira && Carbon::parse($cuenta->suscripcion_expira, 'UTC')->gt(now('UTC')->addHours(24))) {
            return;
        }

        $http = $this->graph->http($cuenta);
        $vence = now('UTC')->addMinutes(4200);

        try {
            if ($cuenta->suscripcion_id) {
                $r = $http->patch("/subscriptions/{$cuenta->suscripcion_id}", ['expirationDateTime' => $vence->format('Y-m-d\TH:i:s.0000000\Z')]);
                if ($r->successful()) {
                    DB::table('panel_ms_cuentas')->where('id', $cuenta->id)->update(['suscripcion_expira' => $vence]);

                    return;
                }
            }
            $r = $this->graph->revisar($http->post('/subscriptions', [
                'changeType' => 'created,updated,deleted',
                'notificationUrl' => $url,
                'resource' => 'me/events',
                'expirationDateTime' => $vence->format('Y-m-d\TH:i:s.0000000\Z'),
                'clientState' => $this->clientState($cuenta),
            ]));
            DB::table('panel_ms_cuentas')->where('id', $cuenta->id)->update(['suscripcion_id' => $r->json('id'), 'suscripcion_expira' => $vence]);
        } catch (Throwable $e) {
            Log::warning('Microsoft: no se pudo crear la suscripción — '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------ utilidades

    private function evento(int $id): ?object
    {
        $p = DB::getTablePrefix();

        return DB::selectOne("SELECT a.id, a.label, a.datep, a.note, COALESCE(ss.ref, cc.ref, oo.ref) ref, COALESCE(s.nom, 'Sin empresa') empresa
            FROM {$p}actioncomm a
            LEFT JOIN {$p}solicitudes_solicitud ss ON ss.rowid = a.fk_element AND a.elementtype = 'solicitud'
            LEFT JOIN {$p}cotizaciones_clientecotizacion cc ON cc.rowid = a.fk_element AND a.elementtype = 'clientecotizacion'
            LEFT JOIN {$p}ordencompra_orden oo ON oo.rowid = a.fk_element AND a.elementtype = 'orden'
            LEFT JOIN {$p}societe s ON s.rowid = COALESCE(ss.fk_soc, cc.fk_soc, oo.fk_soc)
            WHERE a.id = ?", [$id]);
    }

    private function observacion(object $ev): string
    {
        return trim(explode(ActividadesService::SEP, (string) $ev->note, 2)[0]);
    }

    private function cuerpo(object $ev): string
    {
        return '<p><b>'.e($ev->ref).'</b> · '.e($ev->empresa).'</p><p>'.nl2br(e($this->observacion($ev))).'</p><p style="color:#888">Programado desde Industry</p>';
    }

    private function fecha(Carbon $c): array
    {
        return ['dateTime' => $c->format('Y-m-d\TH:i:s'), 'timeZone' => MicrosoftGraphService::TZ_MS];
    }

    private function huella(string $asunto, Carbon $inicio): string
    {
        return md5(trim($asunto).'|'.$inicio->format('Y-m-d H:i'));
    }

    private function listaTareas(object $cuenta, $http): string
    {
        if ($cuenta->lista_tareas_id) {
            return $cuenta->lista_tareas_id;
        }
        $nombre = self::LISTA_TAREAS;
        $r = $this->graph->revisar($http->get('/me/todo/lists?$top=100'));
        $id = collect($r->json('value', []))->firstWhere('displayName', $nombre)['id'] ?? null;
        $id ??= $this->graph->revisar($http->post('/me/todo/lists', ['displayName' => $nombre]))->json('id');
        DB::table('panel_ms_cuentas')->where('id', $cuenta->id)->update(['lista_tareas_id' => $id]);
        $cuenta->lista_tareas_id = $id;

        return $id;
    }
}
