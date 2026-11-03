<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Factura extends Model
{
    protected $table = 'facture';
    public $timestamps = false;
    protected $primaryKey = "rowid";

    public function extrafields()
    {
        return $this->hasOne(FacturaExtrafields::class, 'fk_object', 'rowid');
    }

    public function domicilio()
    {
        return $this->hasOne(Domicilio::class, 'fk_facture', 'rowid');
    }

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'fk_user_valid', 'rowid');
    }

    public function lineas()
    {
        return $this->hasMany(FacturaDetalle::class, 'fk_facture', 'rowid');
    }

    public function notasCredito()
    {
        return $this->hasMany(self::class, 'fk_facture_source', 'rowid');
    }

    public function pagos()
    {
        return $this->hasMany(PagoFactura::class, 'fk_facture', 'rowid');
    }

    public function descuentosExcepcionales()
    {
        return $this->hasMany(DescuentoExcepcional::class, 'fk_facture', 'rowid');
    }
}
