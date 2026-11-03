<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TomaFisicaDet extends Model
{
    protected $table = 'integros_tomafisica_det';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'idproducto', 'rowid');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'fk_user', 'rowid');
    }
}
