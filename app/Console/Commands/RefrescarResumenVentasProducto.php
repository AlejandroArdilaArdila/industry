<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula llx_integros_resumen_ventas_producto (SUM(qty)/SUM(total_ttc) de
 * llx_facturedet, agrupado por producto y ano de llx_facture.datef, solo
 * facturas validadas/pagadas fk_statut IN (1,2)).
 *
 * Paga UNA vez el costo del join+GROUP BY pesado (400k+ filas de facturedet)
 * en vez de pagarlo en cada request de dashboard: los widgets "top vendidos"
 * / "stock bajo vendido" leen de esta tabla resumen (unas pocas miles de
 * filas) en vez de agregar en vivo.
 */
class RefrescarResumenVentasProducto extends Command
{
    protected $signature = 'informes:refrescar-resumen-ventas-producto';

    protected $description = 'Recalcula el resumen de ventas por producto/anio usado por los widgets de dashboard "top vendidos" y "stock bajo vendido"';

    public function handle(): int
    {
        $db = DB::connection('mysql');
        $prefix = $db->getTablePrefix();

        // DB::statement() con SQL crudo no aplica el prefijo de tabla de Laravel
        // (a diferencia de ->table()), asi que hay que ponerlo a mano.
        $db->statement(
            "REPLACE INTO {$prefix}integros_resumen_ventas_producto (fk_product, anio, cantidad, total_facturado)
             SELECT
                 fd.fk_product,
                 YEAR(f.datef) AS anio,
                 SUM(fd.qty) AS cantidad,
                 SUM(fd.total_ttc) AS total_facturado
             FROM {$prefix}facturedet fd
             INNER JOIN {$prefix}facture f ON f.rowid = fd.fk_facture
             WHERE f.fk_statut IN (1, 2)
               AND fd.fk_product IS NOT NULL
             GROUP BY fd.fk_product, YEAR(f.datef)"
        );

        $this->info('Resumen de ventas por producto/anio actualizado.');

        return self::SUCCESS;
    }
}
