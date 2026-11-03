<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoPrecioProveedor extends Model
{
    protected $table = 'product_fournisseur_price';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }
}
