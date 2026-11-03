<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoImpuestoExtra extends Model
{
    protected $table = 'integros_product_impuestos_extra';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }
}
