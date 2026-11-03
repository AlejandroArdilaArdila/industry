<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resumen precalculado de ventas por producto/anio (llx_facturedet + llx_facture
 * agregados), para que los widgets de dashboard que necesitan "ventas del ano
 * actual por producto" (top vendidos, stock bajo vendido) lean de aca en vez
 * de agregar en vivo sobre 400k+ filas en cada request. Se refresca via
 * `php artisan informes:refrescar-resumen-ventas-producto` (programado en
 * bootstrap/app.php withSchedule).
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        DB::statement("CREATE TABLE IF NOT EXISTS {$prefix}integros_resumen_ventas_producto (
            fk_product INT NOT NULL,
            anio INT NOT NULL,
            cantidad DOUBLE(24,8) NOT NULL DEFAULT 0,
            total_facturado DOUBLE(24,8) NOT NULL DEFAULT 0,
            tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (fk_product, anio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    public function down(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        DB::statement("DROP TABLE IF EXISTS {$prefix}integros_resumen_ventas_producto");
    }
};
