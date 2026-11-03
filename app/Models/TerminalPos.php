<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminalPos extends Model
{
    protected $table = 'pos_cash';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    /**
     * Bloques de sucursal de esta terminal (solo hay filas aqui cuando la
     * variable global SUCURSALES esta/estuvo activa y el cajero ya se
     * guardo con el formulario nuevo por bloques).
     */
    public function sucursales()
    {
        return $this->hasMany(TerminalPosSucursal::class, 'fk_cash', 'rowid');
    }
}
