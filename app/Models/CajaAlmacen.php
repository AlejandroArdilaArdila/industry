<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Almacen hijo asignado a una caja (llx_integrospos_cajas_almacen).
 */
class CajaAlmacen extends Model
{
    protected $table = 'integrospos_cajas_almacen';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function almacen()
    {
        return $this->belongsTo(Almacen::class, 'fk_warehouse', 'rowid');
    }
}
