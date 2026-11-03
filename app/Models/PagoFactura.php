<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoFactura extends Model
{
    protected $table = 'paiement_facture';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function factura()
    {
        return $this->belongsTo(Factura::class, 'fk_facture', 'rowid');
    }
}
