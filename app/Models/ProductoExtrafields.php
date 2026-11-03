<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoExtrafields extends Model
{
    protected $table = 'product_extrafields';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_object', 'rowid');
    }

    public function plantilla()
    {
        return $this->belongsTo(PlantillaTicket::class, 'fk_plantilla_contable', 'rowid');
    }
}
