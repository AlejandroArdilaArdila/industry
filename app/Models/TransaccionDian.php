<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Transaccion DIAN (CUFE) de la factura electronica. Vive en una tabla
 * distinta segun el tipo de documento (1=factura, 2=factura POS).
 */
class TransaccionDian extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    private const TABLAS_POR_TIPO = [
        1 => 'facturacione_config_factura_transaccion_dian',
        2 => 'facturacione_config_factura_pos_transaccion_dian',
    ];

    public static function cufePorFactura(int $tipoFac, int $idFactura): ?string
    {
        if (!isset(self::TABLAS_POR_TIPO[$tipoFac])) {
            return null;
        }

        return (new static)
            ->setTable(self::TABLAS_POR_TIPO[$tipoFac])
            ->newQuery()
            ->where('fk_factura', $idFactura)
            ->value('cufe');
    }

    /**
     * Crea el registro de transaccion DIAN para la factura si aun no existe.
     * El json_dian y los demas datos de la respuesta los completa el envio a la DIAN.
     */
    public static function eliminarPorFactura(int $tipoFac, int $idFactura): void
    {
        if (!isset(self::TABLAS_POR_TIPO[$tipoFac])) {
            return;
        }

        (new static)
            ->setTable(self::TABLAS_POR_TIPO[$tipoFac])
            ->newQuery()
            ->where('fk_factura', $idFactura)
            ->delete();
    }

    public static function asegurarRegistro(int $tipoFac, int $idFactura): void
    {
        if (!isset(self::TABLAS_POR_TIPO[$tipoFac])) {
            return;
        }

        $query = (new static)
            ->setTable(self::TABLAS_POR_TIPO[$tipoFac])
            ->newQuery();

        if ($query->where('fk_factura', $idFactura)->exists()) {
            return;
        }

        $query->insert([
            'fk_factura' => $idFactura,
            'date_creation' => now(),
            'estado' => 0,
            'cufe' => 'cufe',
            'type_code' => 'code',
            'remission_h' => 'remison',
            'remission_d' => '2025-01-01',
            'qr' => 'qr',
            'id_dian' => 'id',
            'json_dian' => '{}',
            'estado_descripcion' => 'sin enviar',
            'estado_mensaje' => 'sin enviar',
        ]);
    }
}
