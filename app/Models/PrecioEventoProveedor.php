<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Precio por evento de un producto para un proveedor
 * (llx_integros_precio_evento_proveedor).
 */
class PrecioEventoProveedor extends Model
{
    protected $table = 'integros_precio_evento_proveedor';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function proveedor()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }

    /**
     * Productos con precio por evento de un proveedor, con los datos del
     * tercero y del producto (y su codigo de barras extrafield), ordenados
     * por proveedor y descripcion del producto. Los alias de columnas
     * (id_proveedor, nit, referencia, ...) son los del listado del legado.
     * Sin alias de tabla: con DB_PREFIX el builder solo prefija los nombres
     * completos.
     */
    public function scopeListadoDeProveedor($query, int $idProveedor)
    {
        $pe = $this->getTable();

        return $query
            ->join('societe', 'societe.rowid', '=', "{$pe}.fk_soc")
            ->join('product', 'product.rowid', '=', "{$pe}.fk_product")
            ->leftJoin('product_extrafields', 'product_extrafields.fk_object', '=', 'product.rowid')
            ->where("{$pe}.fk_soc", $idProveedor)
            ->select([
                'societe.rowid as id_proveedor',
                'societe.siren as nit',
                'societe.nom as proveedor',
                'product.rowid as id_producto',
                'product.ref as referencia',
                'product.label as descripcion',
                'product_extrafields.codigo_barras as codigo_barras',
                "{$pe}.embalaje as embalaje",
                "{$pe}.costo_evento as costo_evento_antes_iva",
                "{$pe}.iva as iva",
                "{$pe}.descuento as descuento",
                "{$pe}.otros_impuestos as otros_impuestos",
                "{$pe}.total_costo as total_costo_evento",
                "{$pe}.precio_afiliado as precio_afiliado",
                "{$pe}.estado as estado",
                'product.price as precio_venta_erp',
                "{$pe}.fecha_creacion as fecha_creacion",
                "{$pe}.fecha_modif as fecha_modif",
            ])
            ->orderBy('societe.nom')
            ->orderBy('product.label');
    }

    /**
     * Resumen de precios por evento de un proveedor, con los alias de columnas
     * de la consulta original (referencia, descripcion, codigos_barras,
     * precio_base_sin_iva, costo_neto_sin_iva, ...). codigos_barras sale de
     * product_barcode y, si el producto no tiene ninguno, del extrafield
     * codigo_barras (limpiando [ ] " de su JSON). Las expresiones raw usan el
     * nombre completo de las tablas (con prefijo): el builder no prefija
     * dentro de un raw.
     */
    public function scopeResumenDeProveedor($query, int $idProveedor)
    {
        $pe = $this->getTable();
        $prefijo = $this->getConnection()->getTablePrefix();
        $tPe = $prefijo.$pe;
        $tProd = $prefijo.'product';
        $tEx = $prefijo.'product_extrafields';
        $tBar = $prefijo.'product_barcode';
        $tStock = $prefijo.'product_stock';

        return $query
            ->join('product', 'product.rowid', '=', "{$pe}.fk_product")
            ->leftJoin('product_extrafields', 'product_extrafields.fk_object', '=', 'product.rowid')
            ->where("{$pe}.fk_soc", $idProveedor)
            ->selectRaw("
                {$tProd}.rowid AS id_producto,
                {$tProd}.ref AS referencia,
                {$tProd}.label AS descripcion,
                COALESCE(
                    (SELECT GROUP_CONCAT(b.barcode ORDER BY b.rowid SEPARATOR ', ')
                       FROM {$tBar} b WHERE b.fk_product = {$tProd}.rowid),
                    NULLIF(REPLACE(REPLACE(REPLACE(REPLACE({$tEx}.codigo_barras, '[', ''), ']', ''), '\"', ''), ',', ', '), '')
                ) AS codigos_barras,
                IFNULL((SELECT SUM(ps.reel) FROM {$tStock} ps WHERE ps.fk_product = {$tProd}.rowid), 0) AS stock,
                {$tPe}.embalaje AS embalaje,
                {$tPe}.costo_evento AS costo_evento_unidad,
                {$tPe}.costo_evento * {$tPe}.embalaje AS precio_base_sin_iva,
                {$tPe}.iva AS pct_iva,
                {$tPe}.descuento AS pct_desc_proveedor,
                {$tPe}.total_costo AS total_costo_con_iva,
                {$tPe}.total_costo / (1 + {$tPe}.iva / 100) AS costo_neto_sin_iva,
                {$tPe}.precio_afiliado AS precio_afiliado_con_iva,
                {$tPe}.precio_afiliado / (1 + {$tPe}.iva / 100) AS precio_afiliado_sin_iva,
                {$tPe}.ganancia_sin_iva AS ganancia_sin_iva,
                {$tPe}.porcentaje_ganancia AS pct_ganancia,
                {$tPe}.descuento_cliente AS pct_desc_cliente_sin_iva,
                {$tPe}.estado AS estado,
                COALESCE({$tPe}.fecha_modif, {$tPe}.fecha_creacion) AS ultima_fecha
            ")
            ->orderBy('product.label');
    }
}
