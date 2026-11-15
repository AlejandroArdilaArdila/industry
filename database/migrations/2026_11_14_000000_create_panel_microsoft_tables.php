<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integración con Office 365 del programador de actividades.
 *  - panel_ms_cuentas:  cuenta Microsoft conectada por cada usuario (tokens cifrados con APP_KEY) y sus preferencias.
 *  - panel_ms_vinculos: relación entre un recordatorio (actioncomm) y su evento de Outlook / tarea de To Do.
 * Idempotente, igual que las demás migraciones del proyecto: se aplica ejecutando up().
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        if (! Schema::hasTable('panel_ms_cuentas')) {
            DB::statement("CREATE TABLE {$prefix}panel_ms_cuentas (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                ms_email VARCHAR(255) NULL,
                ms_nombre VARCHAR(255) NULL,
                access_token TEXT NULL,
                refresh_token TEXT NULL,
                expira_en DATETIME NULL,
                usar_calendario TINYINT(1) NOT NULL DEFAULT 1,
                usar_tareas TINYINT(1) NOT NULL DEFAULT 1,
                usar_teams TINYINT(1) NOT NULL DEFAULT 0,
                lista_tareas_id VARCHAR(255) NULL,
                suscripcion_id VARCHAR(100) NULL,
                suscripcion_expira DATETIME NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'activa',
                ultimo_error VARCHAR(500) NULL,
                ultima_sync DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_panel_ms_cuenta_user (user_id),
                KEY idx_panel_ms_cuenta_sub (suscripcion_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        if (! Schema::hasTable('panel_ms_vinculos')) {
            DB::statement("CREATE TABLE {$prefix}panel_ms_vinculos (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                actioncomm_id INT NOT NULL,
                user_id INT NOT NULL,
                ms_evento_id VARCHAR(255) NULL,
                ms_tarea_id VARCHAR(255) NULL,
                teams_url TEXT NULL,
                huella CHAR(32) NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_panel_ms_vinculo_evento (actioncomm_id),
                KEY idx_panel_ms_vinculo_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_ms_vinculos');
        Schema::dropIfExists('panel_ms_cuentas');
    }
};
