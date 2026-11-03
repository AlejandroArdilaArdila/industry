<?php

namespace App\Services\Panel;

use App\Models\Configuracion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tasas de cambio contra el peso colombiano para el widget TRM del panel.
 *
 *  - USD: TRM oficial de la Superfinanciera (datos.gov.co, dataset 32sa-8pi3).
 *  - Resto: cruce de las tasas del BCE (frankfurter.dev) contra esa TRM:
 *    COP por 1 X = TRM / (X por 1 USD). Las monedas que el BCE no publica salen
 *    de open.er-api.com (solo desde el día en que se agregan).
 *
 * Todo se guarda por día en panel_trm_historial; el widget y el historial leen de ahí.
 */
class TrmService
{
    public const CONST_MONEDAS = 'PANEL_TRM_MONEDAS';

    public const CONST_COLORES = 'PANEL_TRM_COLORES';

    public const DEFAULT = ['USD', 'EUR', 'GBP', 'MXN', 'BRL'];

    public const MAX_MONEDAS = 12;

    private const URL_TRM = 'https://www.datos.gov.co/resource/32sa-8pi3.json';

    private const URL_BCE = 'https://api.frankfurter.dev/v1';

    private const URL_ER = 'https://open.er-api.com/v6/latest/USD';

    private const TABLA = 'panel_trm_historial';

    // ------------------------------------------------------------ configuración

    /** @return string[] Las dos primeras se muestran siempre; el resto al ampliar. */
    public function monedasConfiguradas(): array
    {
        $json = Configuracion::valores([self::CONST_MONEDAS])[self::CONST_MONEDAS] ?? null;
        $lista = is_string($json) ? json_decode($json, true) : null;
        $lista = is_array($lista) ? array_values(array_unique(array_filter($lista, fn ($c) => is_string($c) && preg_match('/^[A-Z]{3}$/', $c)))) : [];

        return $lista ?: self::DEFAULT;
    }

    public function guardarMonedas(array $monedas): void
    {
        $this->guardarConst(self::CONST_MONEDAS, json_encode(array_values($monedas)), 'Monedas del widget TRM del panel');
        Cache::forget('trm:tasas');
    }

    /** @return array{general: ?string, borde: ?string, fondo: ?string, fuente: ?string, monedas: array<string, string>} colores #RRGGBB; null = el del tema del panel */
    public function coloresConfigurados(): array
    {
        $json = Configuracion::valores([self::CONST_COLORES])[self::CONST_COLORES] ?? null;
        $c = is_string($json) ? json_decode($json, true) : null;
        $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;

        $monedas = [];
        foreach ((array) ($c['monedas'] ?? []) as $codigo => $valor) {
            if (preg_match('/^[A-Z]{3}$/', (string) $codigo) && $hex($valor)) {
                $monedas[$codigo] = $hex($valor);
            }
        }

        return [
            'general' => $hex($c['general'] ?? null),
            'borde' => $hex($c['borde'] ?? null),
            'fondo' => $hex($c['fondo'] ?? null),
            'fuente' => $hex($c['fuente'] ?? null),
            'monedas' => $monedas,
        ];
    }

    public function guardarColores(array $tema, array $monedas): void
    {
        $this->guardarConst(self::CONST_COLORES, json_encode([
            'general' => $tema['general'] ?? null,
            'borde' => $tema['borde'] ?? null,
            'fondo' => $tema['fondo'] ?? null,
            'fuente' => $tema['fuente'] ?? null,
            'monedas' => (object) $monedas,
        ]), 'Colores del widget TRM del panel');
    }

    private function guardarConst(string $nombre, string $valor, string $nota): void
    {
        $q = DB::table('const')->where('name', $nombre)->where('entity', 1);
        if ($q->exists()) {
            $q->update(['value' => $valor]);
        } else {
            DB::table('const')->insert(['name' => $nombre, 'entity' => 1, 'value' => $valor, 'type' => 'chaine', 'visible' => 0, 'note' => $nota]);
        }
    }

    /** @return array<string, string> código => 'bce' | 'er' (de dónde sale su histórico) */
    public function catalogo(): array
    {
        return Cache::remember('trm:catalogo', 86400, function () {
            $bce = $this->http(self::URL_BCE.'/currencies');
            $er = $this->http(self::URL_ER);
            $out = [];
            foreach (array_keys((array) ($er['rates'] ?? [])) as $c) {
                $out[$c] = 'er';
            }
            foreach (array_keys((array) $bce) as $c) {
                $out[$c] = 'bce';
            }
            if (! $out) {
                throw new RuntimeException('No se pudo consultar el catálogo de monedas.');
            }
            unset($out['COP']);
            ksort($out);

            return $out;
        });
    }

    // ------------------------------------------------------------ lectura

    public function tasas(): array
    {
        $monedas = $this->monedasConfiguradas();
        $this->asegurarActual($monedas);

        return Cache::remember('trm:tasas:'.md5(implode(',', $monedas)), 300, function () use ($monedas) {
            $items = [];
            foreach ($monedas as $codigo) {
                $filas = DB::table(self::TABLA)->where('moneda', $codigo)->where('fecha', '<=', Carbon::today()->toDateString())
                    ->orderByDesc('fecha')->limit(30)->get(['fecha', 'valor', 'fuente']);
                if ($filas->isEmpty()) {
                    $items[] = ['codigo' => $codigo, 'valor' => null, 'anterior' => null, 'fecha' => null, 'fuente' => null, 'serie' => []];

                    continue;
                }
                $actual = (float) $filas[0]->valor;
                $previa = $filas->first(fn ($f) => abs((float) $f->valor - $actual) > 1e-9);
                $items[] = [
                    'codigo' => $codigo,
                    'valor' => $actual,
                    'anterior' => $previa ? (float) $previa->valor : null,
                    'fecha' => $filas[0]->fecha,
                    'fuente' => $filas[0]->fuente,
                    'serie' => $filas->reverse()->pluck('valor')->map(fn ($v) => (float) $v)->values()->all(),
                ];
            }

            return [
                'base' => 'COP',
                'items' => $items,
                'ultimaCarga' => DB::table(self::TABLA)->max('updated_at'),
                'generado' => now()->toIso8601String(),
            ];
        });
    }

    public function historial(string $moneda, string $desde, string $hasta): array
    {
        $desde = Carbon::parse($desde)->startOfDay();
        $hasta = Carbon::parse($hasta)->startOfDay();
        if ($hasta->lt($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        if ($desde->diffInDays($hasta) > 1830) {
            $desde = $hasta->copy()->subDays(1830);
        }

        $this->asegurarHistorial($moneda, $desde);

        $filas = DB::table(self::TABLA)->where('moneda', $moneda)->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->orderBy('fecha')->get(['fecha', 'valor', 'fuente'])
            ->map(fn ($f) => ['fecha' => $f->fecha, 'valor' => (float) $f->valor, 'fuente' => $f->fuente])->all();

        $valores = array_column($filas, 'valor');
        $stats = null;
        if ($valores) {
            $primero = $valores[0];
            $ultimo = end($valores);
            $stats = [
                'ultimo' => $ultimo,
                'minimo' => min($valores),
                'maximo' => max($valores),
                'promedio' => array_sum($valores) / count($valores),
                'variacion' => $primero ? (($ultimo - $primero) / $primero) * 100 : null,
                'dias' => count($valores),
            ];
        }

        return ['moneda' => $moneda, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'filas' => $filas, 'stats' => $stats];
    }

    // ------------------------------------------------------------ carga

    /**
     * Descarga y guarda (upsert) las tasas desde $desde (por defecto, los últimos 10 días).
     *
     * @return int filas escritas
     */
    public function cargar(array $monedas, ?string $desde = null): int
    {
        $monedas = array_values(array_unique(array_merge(['USD'], $monedas)));
        $hoy = Carbon::today();
        $desde = $desde ? Carbon::parse($desde)->startOfDay() : $hoy->copy()->subDays(10);

        $trm = $this->trmPorDia($desde->copy()->subDays(10), $hoy);
        if (! $trm) {
            throw new RuntimeException('No se pudo obtener la TRM oficial.');
        }

        $filas = [];
        foreach ($trm as $dia => $valor) {
            if ($dia >= $desde->toDateString()) {
                $filas[] = $this->fila($dia, 'USD', $valor, 'superfinanciera');
            }
        }

        $otras = array_values(array_diff($monedas, ['USD']));
        if ($otras) {
            $catalogo = $this->catalogo();
            $enBce = array_values(array_filter($otras, fn ($c) => ($catalogo[$c] ?? null) === 'bce'));
            $soloEr = array_values(array_filter($otras, fn ($c) => ($catalogo[$c] ?? null) !== 'bce'));

            if ($enBce) {
                $serie = $this->http(self::URL_BCE.'/'.$desde->toDateString().'..', ['base' => 'USD', 'symbols' => implode(',', $enBce)])['rates'] ?? null;
                if ($serie) {
                    foreach ($serie as $dia => $tasas) {
                        $t = $this->trmEnOAntes($trm, $dia);
                        foreach ($tasas as $c => $porUsd) {
                            if ($t && $porUsd > 0) {
                                $filas[] = $this->fila($dia, $c, $t / $porUsd, 'bce');
                            }
                        }
                    }
                } else {
                    $soloEr = array_merge($soloEr, $enBce);
                }
            }

            if ($soloEr) {
                $er = $this->http(self::URL_ER)['rates'] ?? [];
                $t = $this->trmEnOAntes($trm, $hoy->toDateString());
                foreach ($soloEr as $c) {
                    if ($t && ! empty($er[$c])) {
                        $filas[] = $this->fila($hoy->toDateString(), $c, $t / $er[$c], 'er-api');
                    }
                }
            }
        }

        foreach (array_chunk($filas, 500) as $lote) {
            DB::table(self::TABLA)->upsert($lote, ['fecha', 'moneda'], ['valor', 'fuente', 'updated_at']);
        }
        Cache::forget('trm:tasas:'.md5(implode(',', $this->monedasConfiguradas())));
        Cache::forget('trm:tasas');

        return count($filas);
    }

    /** Carga perezosa: si hoy aún no hay TRM guardada (cron caído o primera vez), la trae, máximo cada 30 min. */
    private function asegurarActual(array $monedas): void
    {
        $faltan = array_filter($monedas, function ($c) {
            $ultima = DB::table(self::TABLA)->where('moneda', $c)->max('fecha');

            return ! $ultima || $ultima < Carbon::today()->subDays($c === 'USD' ? 0 : 4)->toDateString();
        });

        if ($faltan && Cache::add('trm:intento-actual', 1, 1800)) {
            try {
                $sinDatos = ! DB::table(self::TABLA)->exists();
                $this->cargar($monedas, $sinDatos ? Carbon::today()->subDays(45)->toDateString() : null);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Si el histórico de la moneda no llega hasta $desde, lo completa (una vez por moneda/rango cada 6 h). */
    private function asegurarHistorial(string $moneda, Carbon $desde): void
    {
        $primera = DB::table(self::TABLA)->where('moneda', $moneda)->min('fecha');
        if ($primera && $primera <= $desde->copy()->addDays(7)->toDateString()) {
            return;
        }
        if (Cache::add("trm:intento-hist:{$moneda}:{$desde->format('Y-m')}", 1, 21600)) {
            try {
                $this->cargar([$moneda], $desde->toDateString());
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    // ------------------------------------------------------------ fuentes

    /** @return array<string, float> fecha => TRM vigente ese día (rangos de vigencia expandidos hasta hoy) */
    private function trmPorDia(Carbon $desde, Carbon $hasta): array
    {
        $registros = $this->http(self::URL_TRM, [
            '$where' => "vigenciadesde >= '{$desde->toDateString()}T00:00:00.000'",
            '$order' => 'vigenciadesde',
            '$limit' => 5000,
        ]);

        $mapa = [];
        foreach ((array) $registros as $r) {
            if (! isset($r['valor'], $r['vigenciadesde'])) {
                continue;
            }
            $ini = Carbon::parse($r['vigenciadesde'])->startOfDay();
            $fin = Carbon::parse($r['vigenciahasta'] ?? $r['vigenciadesde'])->startOfDay();
            if ($fin->gt($hasta)) {
                $fin = $hasta->copy();
            }
            for ($d = $ini->copy(); $d->lte($fin); $d->addDay()) {
                $mapa[$d->toDateString()] = (float) $r['valor'];
            }
        }
        ksort($mapa);

        return $mapa;
    }

    private function trmEnOAntes(array $trm, string $dia): ?float
    {
        for ($i = 0; $i < 10; $i++) {
            $k = Carbon::parse($dia)->subDays($i)->toDateString();
            if (isset($trm[$k])) {
                return $trm[$k];
            }
        }

        return null;
    }

    private function fila(string $fecha, string $moneda, float $valor, string $fuente): array
    {
        return ['fecha' => $fecha, 'moneda' => $moneda, 'valor' => round($valor, 6), 'fuente' => $fuente, 'updated_at' => now()];
    }

    private function http(string $url, array $query = []): array
    {
        $res = Http::timeout(10)->retry(2, 300, throw: false)->acceptJson()->get($url, $query);

        return $res->successful() ? ($res->json() ?? []) : [];
    }
}
