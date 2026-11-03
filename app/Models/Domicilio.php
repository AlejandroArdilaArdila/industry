<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domicilio extends Model
{
    protected $table = 'domicilios';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function factura()
    {
        return $this->belongsTo(Factura::class, 'fk_facture', 'rowid');
    }

    public function tipoDomicilio()
    {
        return $this->belongsTo(DomicilioTipo::class, 'fk_tipo_domicilio', 'rowid');
    }

    public function domiciliario()
    {
        return $this->belongsTo(Usuario::class, 'fk_domiciliario', 'rowid');
    }
}
