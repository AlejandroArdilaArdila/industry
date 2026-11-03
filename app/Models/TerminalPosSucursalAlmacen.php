<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Almacen hijo marcado dentro de un bloque de TerminalPosSucursal.
 */
class TerminalPosSucursalAlmacen extends Model
{
    protected $table = 'pos_cash_sucursal_almacen';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function almacen()
    {
        return $this->belongsTo(Almacen::class, 'fk_warehouse', 'rowid');
    }
}
