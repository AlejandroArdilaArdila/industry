<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un bloque "sucursal" de una Terminal POS cuando la variable global
 * SUCURSALES esta activa: un almacen padre (entrepot) con su propia
 * resolucion de facturacion electronica y su propio datafono TEF.
 */
class TerminalPosSucursal extends Model
{
    protected $table = 'pos_cash_sucursal';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function almacenes()
    {
        return $this->hasMany(TerminalPosSucursalAlmacen::class, 'fk_cash_sucursal', 'rowid');
    }

    public function sucursal()
    {
        return $this->belongsTo(Almacen::class, 'fk_warehouse_sucursal', 'rowid');
    }
}
