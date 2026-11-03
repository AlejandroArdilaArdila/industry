<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoBatch extends Model
{
    protected $table = 'product_batch';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function stock()
    {
        return $this->belongsTo(ProductoStock::class, 'fk_product_stock', 'rowid');
    }
}
