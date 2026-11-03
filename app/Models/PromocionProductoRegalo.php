<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromocionProductoRegalo extends Model
{
    protected $table = 'integros_promociones_products_regalos';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function promocionProducto()
    {
        return $this->belongsTo(PromocionProducto::class, 'fk_pp', 'rowid');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }
}
