<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoPrecio extends Model
{
    protected $table = 'product_price';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }

    public function autor()
    {
        return $this->belongsTo(Usuario::class, 'fk_user_author', 'rowid');
    }
}
