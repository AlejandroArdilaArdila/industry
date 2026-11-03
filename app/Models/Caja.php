<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Caja extends Model
{
    protected $table = 'integrospos_cajas';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function almacenes()
    {
        return $this->hasMany(CajaAlmacen::class, 'fk_caja', 'rowid');
    }

    public function sucursal()
    {
        return $this->belongsTo(Almacen::class, 'fk_warehouse_sucursal', 'rowid');
    }
}
