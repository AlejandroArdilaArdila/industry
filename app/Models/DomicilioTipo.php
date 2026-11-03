<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomicilioTipo extends Model
{
    protected $table = 'pos_tipo_domicilio';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }

    public function domicilios()
    {
        return $this->hasMany(Domicilio::class, 'fk_tipo_domicilio', 'rowid');
    }
}
