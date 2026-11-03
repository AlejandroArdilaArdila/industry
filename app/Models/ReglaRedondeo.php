<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tramos de las reglas de redondeo del precio de venta (modulo precios de Dolibarr,
 * ver custom/precios/lib/redondeo.lib.php).
 */
class ReglaRedondeo extends Model
{
    protected $table = 'precios_redondeo_regla';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
}
