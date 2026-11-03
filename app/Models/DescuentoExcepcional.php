<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DescuentoExcepcional extends Model
{
    protected $table = 'societe_remise_except';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function factura()
    {
        return $this->belongsTo(Factura::class, 'fk_facture', 'rowid');
    }
}
