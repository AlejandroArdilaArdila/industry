<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoCodigoBarra extends Model
{
    protected $table = 'product_barcode';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }
}
