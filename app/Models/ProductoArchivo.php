<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoArchivo extends Model
{
    protected $table = 'ecm_files';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'src_object_id', 'rowid');
    }
}
