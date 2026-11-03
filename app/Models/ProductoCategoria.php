<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoCategoria extends Model
{
    protected $table = 'categorie_product';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'fk_product';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }
}
