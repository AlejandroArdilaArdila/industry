<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * validarFacturaPos()/sp_pos_validar_factura_pos hacen, en CADA llamada, un
 * SELECT por ref_local exacto contra facture_extrafields (idempotencia +
 * resolucion de fk_facture) sin indice: full table scan de ~38k filas en
 * cada confirmacion, no solo en carritos grandes. Tambien agrega los indices
 * que faltan en las tablas de puntos de fidelizacion, consultadas en cada
 * confirmacion exitosa (sp_pos_armar_json_factura / sp_pos_datos_adicionales_cliente).
 */
return new class extends Migration
{
    private const INDICES = [
        'facture_extrafields' => ['idx_facture_extrafields_ref_local' => ['ref_local']],
        'pos_puntos_fidelizacion' => ['idx_puntos_fidelizacion_fk_client' => ['fk_client']],
        'pos_puntos_fidelizacion_detalle' => ['idx_puntos_fidelizacion_detalle_fk_facture' => ['fk_facture']],
    ];

    public function up(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        foreach (self::INDICES as $tabla => $indices) {
            foreach ($indices as $nombre => $columnas) {
                if (! $this->indiceExiste($prefix.$tabla, $nombre)) {
                    $listaColumnas = implode(', ', $columnas);
                    DB::statement("ALTER TABLE {$prefix}{$tabla} ADD INDEX {$nombre} ({$listaColumnas})");
                }
            }
        }
    }

    public function down(): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();

        foreach (self::INDICES as $tabla => $indices) {
            foreach (array_keys($indices) as $nombre) {
                if ($this->indiceExiste($prefix.$tabla, $nombre)) {
                    DB::statement("ALTER TABLE {$prefix}{$tabla} DROP INDEX {$nombre}");
                }
            }
        }
    }

    private function indiceExiste(string $tabla, string $nombre): bool
    {
        return DB::connection('mysql')->selectOne(
            'SELECT 1 AS existe FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabla, $nombre]
        ) !== null;
    }
};
