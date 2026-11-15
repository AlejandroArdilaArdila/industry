<?php

namespace App\Services\Ia;

use App\Services\Panel\TareasService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Convierte lo que el usuario dicta o escribe («recuérdame llamar a Imporinco mañana a las 9 por la cotización C2610-38378, urgente»)
 * en los campos del formulario de tarea. La IA solo propone: el usuario confirma o corrige antes de guardar.
 */
class TareaIaService
{
    public function __construct(private IaClient $ia, private IaConfigService $config, private TareasService $tareas)
    {
    }

    public function interpretar(object $usuario, string $texto): array
    {
        $ahora = Carbon::now(TareasService::TZ);
        $cat = $this->tareas->catalogos();
        $nombres = array_column($cat['usuarios'], 'nombre');

        $system = 'Eres el asistente de un CRM comercial colombiano. Conviertes lo que dicta el usuario en los campos de una tarea. '
            .'Responde SOLO un objeto JSON, sin texto adicional, con estas claves (usa null cuando el usuario no lo dijo; no inventes):'
            ."\n- titulo: asunto corto y claro (máx. 80 caracteres), en infinitivo o sustantivo, p. ej. «Llamar a Imporinco por la cotización»."
            ."\n- descripcion: detalles adicionales dictados (null si no hay)."
            ."\n- fecha: YYYY-MM-DD resuelta a partir de la fecha actual (si dice «mañana», «el viernes», «en 15 días»...)."
            ."\n- hora: HH:MM en 24 horas (null si no la dijo)."
            ."\n- prioridad: «urgente» o «medio» (null si no se infiere)."
            ."\n- recurrencia: 1 única, 2 diaria, 3 semanal, 4 mensual (1 por defecto; solo otra si lo pide: «todos los lunes» = 3)."
            ."\n- tipo: uno de solicitud, cotizacion, cotiext (solicitud de cotización a proveedor), orden (orden de compra), importacion, factura, cliente, producto; null si no menciona ningún elemento."
            ."\n- referencia: el texto para buscar ese elemento (código como C2610-38378, RQ2610-34364, S2610-33771, OC2610-10695, o nombre del cliente/producto); null si no hay."
            ."\n- integrantes: lista de nombres de compañeros mencionados a quienes incluir en la tarea (vacía si no hay)."
            ."\n- teams: true si pide reunión/videollamada de Teams, false si no."
            ."\nFecha y hora actual en Bogotá: ".$ahora->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm').' ('.$ahora->toDateString().").\n"
            .'Compañeros disponibles: '.implode(', ', array_slice($nombres, 0, 120)).'.';

        $r = $this->ia->chat($this->config->predeterminado(), $system, [['role' => 'user', 'content' => $texto]], false);
        $t = trim(preg_replace('/^```(?:json)?|```$/m', '', $r));
        $ini = strpos($t, '{');
        $fin = strrpos($t, '}');
        $j = $ini !== false && $fin > $ini ? json_decode(substr($t, $ini, $fin - $ini + 1), true) : null;
        if (! is_array($j)) {
            throw new IaException('No pude interpretar lo que dijiste. Intenta de nuevo con más detalle.');
        }

        $fecha = isset($j['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $j['fecha']) && $j['fecha'] >= $ahora->toDateString() ? $j['fecha'] : null;
        $hora = isset($j['hora']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $j['hora']) ? $j['hora'] : null;
        $prio = null;
        foreach ($cat['prioridades'] as $p) {
            if (isset($j['prioridad']) && self::clave($p['label']) === self::clave((string) $j['prioridad'])) {
                $prio = $p['id'];
            }
        }
        $tipo = isset($j['tipo'], TareasService::TIPOS[$j['tipo']]) ? $j['tipo'] : null;

        [$elemento, $candidatos] = $this->resolverElemento($usuario, $tipo, trim((string) ($j['referencia'] ?? '')));
        [$integrantes, $noEncontrados] = $this->resolverUsuarios((array) ($j['integrantes'] ?? []), $cat['usuarios']);

        return [
            'titulo' => trim((string) ($j['titulo'] ?? '')) ?: null,
            'descripcion' => trim((string) ($j['descripcion'] ?? '')) ?: null,
            'fecha' => $fecha, 'hora' => $hora, 'prioridad' => $prio,
            'recurrencia' => in_array((int) ($j['recurrencia'] ?? 1), [1, 2, 3, 4], true) ? (int) $j['recurrencia'] : 1,
            'elemento' => $elemento, 'candidatos' => $candidatos,
            'integrantes' => $integrantes, 'noEncontrados' => $noEncontrados,
            'teams' => isset($j['teams']) ? (bool) $j['teams'] : null,
        ];
    }

    private static function clave(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', Str::ascii($s))));
    }

    /** @return array{0: ?array, 1: array} elemento único (si hay un solo resultado) y candidatos para elegir */
    private function resolverElemento(object $usuario, ?string $tipo, string $ref): array
    {
        if ($ref === '') {
            return [null, []];
        }
        $tipos = $tipo ? [$tipo] : ['solicitud', 'cotizacion', 'cotiext', 'orden', 'cliente'];
        $res = [];
        foreach ($tipos as $t) {
            foreach ($this->tareas->buscar($usuario, $t, $ref) as $x) {
                $res[] = $x;
            }
            if (count($res) >= 1 && ! $tipo) {
                break; // el primer tipo con resultados gana: los códigos son únicos por tipo
            }
        }
        $res = array_slice($res, 0, 5);
        // Coincidencia exacta de código: se elige sola.
        $exacto = array_values(array_filter($res, fn ($x) => self::clave($x['ref']) === self::clave($ref)));

        return [count($exacto) === 1 ? $exacto[0] : (count($res) === 1 ? $res[0] : null), $res];
    }

    /** @return array{0: array, 1: array<int, string>} */
    private function resolverUsuarios(array $nombres, array $usuarios): array
    {
        $ok = [];
        $no = [];
        foreach ($nombres as $n) {
            $tokens = array_filter(explode(' ', self::clave((string) $n)));
            $m = $tokens ? array_values(array_filter($usuarios, function ($u) use ($tokens) {
                $c = self::clave($u['nombre']);
                foreach ($tokens as $t) {
                    if (! str_contains($c, $t)) {
                        return false;
                    }
                }

                return true;
            })) : [];
            if (count($m) === 1) {
                $ok[$m[0]['id']] = $m[0];
            } else {
                $no[] = (string) $n;
            }
        }

        return [array_values($ok), $no];
    }
}
