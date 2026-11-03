<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacturaDetalleExtrafields extends Model
{
    protected $table = 'facturedet_extrafields';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function facturaDetalle()
    {
        return $this->belongsTo(FacturaDetalle::class, 'fk_object', 'rowid');
    }

    public function plantillaContable()
    {
        return $this->belongsTo(PlantillaContable::class, 'fk_plantilla_contable', 'rowid');
    }
}
