<?php

namespace App\Services\Panel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Actividades vencidas que requieren atención (tareas y recordatorios): atrasadas, o de hoy cuya hora ya pasó.
 * Cada una trae lo necesario para gestionarla sin salir de la alerta: cliente y contacto, cuánto lleva vencida y una sugerencia.
 * El usuario puede silenciarlas por unas horas; si no las gestiona, vuelven a aparecer.
 */
class AlertasService
{
    public const HORAS_SILENCIO = 5;

    private string $p;

    public function __construct(private ActividadesService $actividades, private TareasService $tareas)
    {
        $this->p = DB::getTablePrefix();
    }

    /** @return array{total: int, items: array<int, array>, silenciadas: int, horas: int} */
    public function vencidas(object $usuario, int $limite = 30): array
    {
        $uid = (int) $usuario->getKey();
        $hoy = Carbon::now(TareasService::TZ)->startOfDay();

        $todas = array_merge(
            array_map(fn ($i) => $i + ['clave' => 'tarea:'.$i['id']], $this->tareas->items($usuario)),
            array_map(fn ($i) => ['origen' => 'recordatorio', 'clave' => 'evento:'.$i['id'], 'puedeCambiar' => true, 'propia' => true] + $i, $this->actividades->recordatorios($usuario, 'pendientes', 200)['items']),
        );
        $vencidas = array_values(array_filter($todas, fn ($i) => $i['grupo'] === 'atrasado' || ($i['grupo'] === 'hoy' && $i['horaVencida'])));

        $silenciadas = array_flip(array_column(DB::select("SELECT clave FROM {$this->p}panel_alertas_silencios WHERE user_id = ? AND hasta > ?", [$uid, now()]), 'clave'));
        $visibles = array_values(array_filter($vencidas, fn ($i) => ! isset($silenciadas[$i['clave']])));
        usort($visibles, fn ($a, $b) => strcmp($a['cuando'], $b['cuando']));

        $items = array_slice($visibles, 0, $limite);
        $clientes = $this->clientes($items);
        $fkSoc = $this->fkSocEventos(array_values(array_filter($items, fn ($i) => $i['origen'] === 'recordatorio')));

        $items = array_map(function ($i) use ($hoy, $clientes, $fkSoc) {
            $cuando = Carbon::parse($i['cuando'], TareasService::TZ);
            $dias = (int) $cuando->copy()->startOfDay()->diffInDays($hoy);
            $soc = $i['origen'] === 'tarea' ? ($i['fkSoc'] ?? null) : ($fkSoc[$i['id']] ?? null);

            return [
                'clave' => $i['clave'], 'origen' => $i['origen'], 'id' => $i['id'],
                'titulo' => $i['titulo'], 'ref' => $i['ref'], 'empresa' => $i['empresa'], 'tipoNombre' => $i['moduloNombre'], 'icono' => $i['icono'], 'card' => $i['card'],
                'modulo' => $i['modulo'], 'fkRecord' => $i['fkRecord'], 'cuando' => $i['cuando'], 'diasAtraso' => $dias, 'esHoy' => $dias === 0,
                'observacion' => $i['observacion'], 'prioridad' => $i['prioridad'] ?? null,
                'creador' => ! ($i['propia'] ?? true) ? ($i['creador'] ?? '') : '', 'puedeCambiar' => (bool) ($i['puedeCambiar'] ?? true),
                'cliente' => $soc ? ($clientes[$soc] ?? null) : null,
                'sugerencia' => $this->sugerencia($dias, $i),
            ];
        }, $items);

        return ['total' => count($visibles), 'items' => $items, 'silenciadas' => count($vencidas) - count($visibles), 'horas' => self::HORAS_SILENCIO];
    }

    /** Silencia unas horas las claves indicadas (o todas las vencidas visibles si no se indica ninguna). */
    public function silenciar(object $usuario, ?array $claves): int
    {
        $uid = (int) $usuario->getKey();
        $claves ??= array_column($this->vencidas($usuario, 500)['items'], 'clave');
        $hasta = now()->addHours(self::HORAS_SILENCIO);
        $n = 0;
        foreach (array_unique($claves) as $c) {
            if (preg_match('/^(tarea|evento):\d+$/', (string) $c)) {
                DB::table('panel_alertas_silencios')->updateOrInsert(['user_id' => $uid, 'clave' => $c], ['hasta' => $hasta, 'created_at' => now()]);
                $n++;
            }
        }
        DB::table('panel_alertas_silencios')->where('hasta', '<', now()->subDay())->delete(); // limpieza

        return $n;
    }

    private function sugerencia(int $dias, array $i): string
    {
        $alta = ($i['prioridad']['label'] ?? '') === 'Urgente';
        if ($dias >= 30) {
            return "Lleva {$dias} días vencida: ¿sigue vigente? Ciérrala o reprográmala con una fecha realista.";
        }
        if ($dias >= 7) {
            return 'Lleva más de una semana vencida: gestiónala hoy o pospón con una fecha realista.';
        }
        if ($dias >= 1) {
            return $alta ? 'Es urgente y ya venció: gestiónala ahora.' : 'Venció hace poco: gestiónala hoy o pospón para mañana.';
        }

        return 'Es para hoy y ya pasó la hora: gestiónala ahora o pospón unas horas.';
    }

    /** @param  array<int, array>  $items */
    private function fkSocEventos(array $items): array
    {
        $ids = array_map(fn ($i) => (int) $i['id'], $items);
        if (! $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::select("SELECT id, fk_soc FROM {$this->p}actioncomm WHERE id IN ({$in})", $ids) as $r) {
            $out[(int) $r->id] = $r->fk_soc ? (int) $r->fk_soc : null;
        }

        return $out;
    }

    /** Cliente + contacto principal de cada tarjeta, en pocas consultas. @return array<int, array> por fk_soc */
    private function clientes(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($i) => $i['origen'] === 'tarea' ? ($i['fkSoc'] ?? null) : null, $items))));
        $ev = array_values(array_filter($items, fn ($i) => $i['origen'] === 'recordatorio'));
        $ids = array_values(array_unique(array_merge($ids, array_filter($this->fkSocEventos($ev)))));
        if (! $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::select("SELECT rowid, nom, phone, email FROM {$this->p}societe WHERE rowid IN ({$in})", $ids) as $s) {
            $out[(int) $s->rowid] = ['id' => (int) $s->rowid, 'nombre' => $s->nom, 'telefono' => trim((string) $s->phone), 'email' => trim((string) $s->email), 'contacto' => null, 'otros' => 0];
        }
        foreach (DB::select("SELECT fk_soc, firstname, lastname, email, phone, phone_mobile FROM {$this->p}socpeople WHERE fk_soc IN ({$in}) AND statut = 1 ORDER BY (email <> '') DESC, rowid DESC", $ids) as $c) {
            $k = (int) $c->fk_soc;
            if (! isset($out[$k])) {
                continue;
            }
            if ($out[$k]['contacto'] === null) {
                $out[$k]['contacto'] = [
                    'nombre' => trim($c->firstname.' '.$c->lastname), 'email' => trim((string) $c->email),
                    'telefonos' => array_values(array_unique(array_filter([trim((string) $c->phone_mobile), trim((string) $c->phone)]))),
                ];
            } else {
                $out[$k]['otros']++;
            }
        }

        return $out;
    }
}
