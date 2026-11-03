<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas que usa App\Services\Dashboard\DashboardService (módulo Dashboard Dinámico).
 * Mismo esquema que crea de forma perezosa Dolibarr en
 * custom/informes/lib/dashboard_ajax.php::dashboardGarantizarTablas(), capturado con
 * SHOW CREATE TABLE contra la base real para que quede idéntico (incluye tabs_json y
 * para_app en informes_dashboard, agregadas ahí por fuera de ese archivo).
 * CREATE TABLE IF NOT EXISTS: no destructiva, segura de re-ejecutar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        DB::statement("CREATE TABLE IF NOT EXISTS {$prefix}informes_dashboard (
            rowid INT NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(255) NOT NULL,
            descripcion TEXT DEFAULT NULL,
            color_tema VARCHAR(20) DEFAULT '#6d28d9',
            icono VARCHAR(40) DEFAULT 'fa-chart-line',
            es_publico TINYINT NOT NULL DEFAULT 0,
            fk_user_creat INT NOT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tms TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            tabs_json TEXT DEFAULT NULL,
            para_app TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (rowid),
            KEY idx_fk_user (fk_user_creat),
            KEY idx_publico (es_publico)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci");

        DB::statement("CREATE TABLE IF NOT EXISTS {$prefix}informes_dashboard_widget (
            rowid INT NOT NULL AUTO_INCREMENT,
            fk_dashboard INT NOT NULL,
            tab_nombre VARCHAR(100) NOT NULL DEFAULT 'Principal',
            tab_orden INT NOT NULL DEFAULT 0,
            posicion INT NOT NULL DEFAULT 0,
            pos_x INT NOT NULL DEFAULT 0,
            pos_y INT NOT NULL DEFAULT 0,
            ancho TINYINT NOT NULL DEFAULT 6,
            alto INT NOT NULL DEFAULT 300,
            tipo_grafica VARCHAR(20) NOT NULL DEFAULT 'column',
            titulo VARCHAR(255) DEFAULT '',
            config_json LONGTEXT NOT NULL,
            sql_generado LONGTEXT DEFAULT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tms TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rowid),
            KEY idx_dashboard (fk_dashboard),
            KEY idx_tab (fk_dashboard, tab_orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci");
    }

    public function down(): void
    {
        // No se dropean: son tablas propias de Dolibarr (Dashboard Dinámico), compartidas
        // con el módulo "informes" fuera de esta API. Un rollback no debe borrarlas.
    }
};
