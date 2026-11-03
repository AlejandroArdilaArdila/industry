<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodigoOperativo extends Model
{
    protected $table = 'product_codoperativos';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }
}
