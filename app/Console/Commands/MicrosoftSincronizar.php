<?php

namespace App\Console\Commands;

use App\Services\Microsoft\MicrosoftSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Trae los cambios hechos en Outlook / To Do y renueva las suscripciones de webhook de cada cuenta conectada. */
class MicrosoftSincronizar extends Command
{
    protected $signature = 'microsoft:sincronizar';

    protected $description = 'Sincroniza los recordatorios con Office 365 (Outlook, To Do) y renueva las suscripciones';

    public function handle(MicrosoftSyncService $sync): int
    {
        foreach (DB::table('panel_ms_cuentas')->where('estado', 'activa')->get() as $cuenta) {
            try {
                $r = $sync->sincronizarCuenta($cuenta);
                $sync->asegurarSuscripcion($cuenta);
                $this->line("Usuario {$cuenta->user_id}: ".json_encode($r));
            } catch (Throwable $e) {
                $this->warn("Usuario {$cuenta->user_id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
