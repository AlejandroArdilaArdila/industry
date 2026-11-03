<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca facture_extrafields.es_pedido = 1 cuando FacturacionService::validarFacturaPos()
 * falla (400/406/407/409, o excepcion) confirmando un PEDIDO (no una cotizacion): deja
 * constancia de que esa factura tuvo un intento de confirmacion fallido.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        if (! Schema::hasColumn('facture_extrafields', 'es_pedido')) {
            DB::statement("ALTER TABLE {$prefix}facture_extrafields ADD COLUMN es_pedido TINYINT NOT NULL DEFAULT 0");
        }
    }

    public function down(): void
    {
        Schema::table('facture_extrafields', function ($table) {
            $table->dropColumn('es_pedido');
        });
    }
};
