<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Config de numeracion/resolucion de facturacion. Segun el tipo de
 * documento (1=factura, 2=factura POS, 3=borrador) vive en una tabla distinta,
 * pero con la misma estructura.
 */
class ResolucionFactura extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    private const TABLAS_POR_TIPO = [
        1 => 'facturacione_config_invoice',
        2 => 'facturacione_config_invoice_pos',
        3 => 'facturacione_config_invoice_borrador',
    ];

    public static function activaPara(int $tipoFac): ?self
    {
        $columnas = ['prefijo', 'resolucion', 'fecha_desde', 'fecha_hasta', 'de', 'a'];

        if ($tipoFac === 3) {
            $columnas[] = 'info_qr';
        }

        return (new static)
            ->setTable(self::TABLAS_POR_TIPO[$tipoFac])
            ->newQuery()
            ->where('estado', 1)
            ->first($columnas);
    }
}
