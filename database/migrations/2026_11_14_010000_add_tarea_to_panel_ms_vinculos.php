<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los vínculos con Office 365 también pueden ser de una tarea (solubitacora_tareas): una por integrante conectado.
 * actioncomm_id pasa a ser opcional y se agrega tarea_id. Idempotente: se aplica ejecutando up().
 */
return new class extends Migration
{
    public function up(): void
    {
        $p = Schema::getConnection()->getTablePrefix();
        if (! Schema::hasColumn('panel_ms_vinculos', 'tarea_id')) {
            DB::statement("ALTER TABLE {$p}panel_ms_vinculos MODIFY actioncomm_id INT NULL, ADD COLUMN tarea_id INT NULL AFTER actioncomm_id, ADD UNIQUE KEY uk_panel_ms_vinculo_tarea (tarea_id, user_id)");
        }
    }

    public function down(): void
    {
        Schema::table('panel_ms_vinculos', fn ($t) => $t->dropColumn('tarea_id'));
    }
};
