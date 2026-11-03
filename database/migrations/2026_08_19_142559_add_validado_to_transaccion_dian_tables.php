<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca (facturacione_config_factura_transaccion_dian y su version POS) si
 * el json_dian de esa fila ya fue revisado/corregido (ver
 * FacturacionService::validarDescripcionesJsonDian()): NULL = pendiente por
 * revisar, 1 = ya revisado (las lineas del invoice_lines tienen description).
 */
return new class extends Migration
{
    private const TABLAS = [
        'facturacione_config_factura_transaccion_dian',
        'facturacione_config_factura_pos_transaccion_dian',
    ];

    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'validado')) {
                DB::statement("ALTER TABLE {$prefix}{$tabla} ADD COLUMN validado INT NULL DEFAULT NULL");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasColumn($tabla, 'validado')) {
                Schema::table($tabla, function ($table) {
                    $table->dropColumn('validado');
                });
            }
        }
    }
};
