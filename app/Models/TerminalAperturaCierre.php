<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminalAperturaCierre extends Model
{
    protected $table = 'integrospos_terminal_aperturacierre';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function facturaExtrafields()
    {
        return $this->hasMany(FacturaExtrafields::class, 'fk_apertura', 'rowid');
    }

    public function almacen()
    {
        return $this->belongsTo(Almacen::class, 'fk_warehouse', 'rowid');
    }

    public function turno()
    {
        return $this->belongsTo(Turno::class, 'fk_turno', 'rowid');
    }
}
