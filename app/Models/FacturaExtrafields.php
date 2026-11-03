<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacturaExtrafields extends Model
{
     protected $table = 'facture_extrafields';
    public $timestamps = false;
    protected $primaryKey = "rowid";

    public function factura(){
        return $this->belongsTo(Factura::class,   "fk_object", "rowid");
    }

    public function terminalAperturaCierre()
    {
        return $this->belongsTo(TerminalAperturaCierre::class, 'fk_apertura', 'rowid');
    }

}
