<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vendedor(es) asignado(s) a un cliente (societe_commerciaux).
 */
class SociedadComercial extends Model
{
    protected $table = 'societe_commerciaux';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
}
