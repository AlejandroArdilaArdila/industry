<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'product';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function lineasFactura()
    {
        return $this->hasMany(FacturaDetalle::class, 'fk_product', 'rowid');
    }

    public function barcodes()
    {
        return $this->hasMany(ProductoCodigoBarra::class, 'fk_product', 'rowid');
    }

    public function tiposDomicilio()
    {
        return $this->hasMany(DomicilioTipo::class, 'fk_product', 'rowid');
    }

    public function extrafields()
    {
        return $this->hasOne(ProductoExtrafields::class, 'fk_object', 'rowid');
    }

    public function precios()
    {
        return $this->hasMany(ProductoPrecio::class, 'fk_product', 'rowid');
    }

    public function packs()
    {
        return $this->hasMany(ProductoPack::class, 'fk_product', 'rowid');
    }

    public function impuestosExtra()
    {
        return $this->hasMany(ProductoImpuestoExtra::class, 'fk_product', 'rowid');
    }

    public function categorias()
    {
        return $this->hasMany(ProductoCategoria::class, 'fk_product', 'rowid');
    }

    public function imagenPrincipal()
    {
        return $this->hasOne(ProductoArchivo::class, 'src_object_id', 'rowid')
            ->where('src_object_type', 'product')
            ->oldest('rowid');
    }

    public function precioProveedor()
    {
        return $this->hasOne(ProductoPrecioProveedor::class, 'fk_product', 'rowid')
            ->oldest('rowid');
    }
}
