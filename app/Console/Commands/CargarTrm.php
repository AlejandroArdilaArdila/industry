<?php

namespace App\Console\Commands;

use App\Services\Panel\TrmService;
use Illuminate\Console\Command;

/**
 * Carga la TRM (USD oficial) y las demás monedas configuradas del widget TRM en
 * panel_trm_historial. Es idempotente: se puede correr varias veces al día.
 */
class CargarTrm extends Command
{
    protected $signature = 'trm:cargar {--desde= : Fecha YYYY-MM-DD desde la que cargar (por defecto, los últimos 10 días)} {--moneda=* : Solo estas monedas (por defecto, las configuradas)}';

    protected $description = 'Carga la TRM del día y su historial de las monedas configuradas en el widget del panel';

    public function handle(TrmService $trm): int
    {
        $monedas = array_map('strtoupper', $this->option('moneda')) ?: $trm->monedasConfiguradas();

        try {
            $n = $trm->cargar($monedas, $this->option('desde') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("TRM cargada: {$n} registros (".implode(', ', $monedas).').');

        return self::SUCCESS;
    }
}
