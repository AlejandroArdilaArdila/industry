<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sociedad extends Model
{
    protected $table = 'societe';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function facturas()
    {
        return $this->hasMany(Factura::class, 'fk_soc', 'rowid');
    }

    public function extrafields()
    {
        return $this->hasOne(SociedadExtrafields::class, 'fk_object', 'rowid');
    }

    public function ciudadFe()
    {
        return $this->belongsTo(CiudadFe::class, 'zip', 'code');
    }

    public function ciiu()
    {
        return $this->belongsTo(CodigoCiiu::class, 'ape', 'codigo');
    }

    public function medioPago()
    {
        return $this->belongsTo(MedioPago::class, 'mode_reglement', 'id');
    }

    public function terminoPago()
    {
        return $this->belongsTo(TerminoPago::class, 'cond_reglement', 'rowid');
    }

    public function puntosFidelizacion()
    {
        return $this->hasOne(PuntoFidelizacion::class, 'fk_client', 'rowid');
    }

    public function vendedores()
    {
        return $this->hasMany(SociedadComercial::class, 'fk_soc', 'rowid');
    }

    public function contactos()
    {
        return $this->hasMany(Contacto::class, 'fk_soc', 'rowid');
    }

    public function propuestas()
    {
        return $this->hasMany(Propuesta::class, 'fk_soc', 'rowid');
    }

    public function ordenesVenta()
    {
        return $this->hasMany(OrdenVenta::class, 'fk_soc', 'rowid');
    }
}
