<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromocionProducto extends Model
{
    protected $table = 'integros_promociones_products';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function promocion()
    {
        return $this->belongsTo(Promocion::class, 'fk_prom', 'rowid');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }

    public function regalos()
    {
        return $this->hasMany(PromocionProductoRegalo::class, 'fk_pp', 'rowid');
    }
}
