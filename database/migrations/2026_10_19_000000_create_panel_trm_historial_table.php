<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial diario de tasas de cambio contra el peso colombiano (COP por 1 unidad de la moneda),
 * alimentado por el comando trm:cargar (ver App\Services\Panel\TrmService).
 * Idempotente, igual que las demás migraciones del proyecto: se aplica ejecutando up().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('panel_trm_historial')) {
            return;
        }

        $prefix = Schema::getConnection()->getTablePrefix();

        DB::statement("CREATE TABLE {$prefix}panel_trm_historial (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            fecha DATE NOT NULL,
            moneda CHAR(3) NOT NULL,
            valor DECIMAL(18,6) NOT NULL,
            fuente VARCHAR(20) NOT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_panel_trm_fecha_moneda (fecha, moneda),
            KEY idx_panel_trm_moneda_fecha (moneda, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_trm_historial');
    }
};
