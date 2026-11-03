<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige 2026_08_02_020000_add_es_pedido_to_facture_extrafields: el nombre
 * correcto de la columna ya existente y usada por CotizacionService::listar()
 * es 'en_pedido', no 'es_pedido'. Se borra la columna equivocada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('facture_extrafields', 'es_pedido')) {
            Schema::table('facture_extrafields', function ($table) {
                $table->dropColumn('es_pedido');
            });
        }
    }

    public function down(): void
    {
        Schema::table('facture_extrafields', function ($table) {
            $table->tinyInteger('es_pedido')->default(0);
        });
    }
};
