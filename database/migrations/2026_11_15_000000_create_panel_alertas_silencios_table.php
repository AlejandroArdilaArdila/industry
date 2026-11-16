<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alertas de actividades vencidas que el usuario silenció (vuelven a mostrarse cuando vence `hasta`).
 * clave = «tarea:ID» o «evento:ID». Idempotente: se aplica ejecutando up().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('panel_alertas_silencios')) {
            return;
        }
        $p = Schema::getConnection()->getTablePrefix();
        DB::statement("CREATE TABLE {$p}panel_alertas_silencios (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            clave VARCHAR(40) NOT NULL,
            hasta DATETIME NOT NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_panel_alerta_user_clave (user_id, clave),
            KEY idx_panel_alerta_hasta (hasta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_alertas_silencios');
    }
};
