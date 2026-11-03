<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoStock extends Model
{
    protected $table = 'product_stock';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }

    public function lotes()
    {
        return $this->hasMany(ProductoBatch::class, 'fk_product_stock', 'rowid');
    }
}
