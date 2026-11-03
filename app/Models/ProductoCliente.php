<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoCliente extends Model
{
    protected $table = 'integros_producto_cliente';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }
}
