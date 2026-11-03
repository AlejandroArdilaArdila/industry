<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacturaDetalle extends Model
{
    protected $table = 'facturedet';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function factura()
    {
        return $this->belongsTo(Factura::class, 'fk_facture', 'rowid');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }

    public function productoDomicilio()
    {
        return $this->belongsTo(DomicilioTipo::class, 'fk_product', 'fk_product');
    }

    public function extrafields()
    {
        return $this->hasOne(FacturaDetalleExtrafields::class, 'fk_object', 'rowid');
    }
}
