<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pre-orden de venta del modulo integrospresta (espejo de llx_commande).
 * Vive en llx_integros_precommande hasta que se convierte en orden real.
 */
class Preorden extends Model
{
    protected $table = 'integros_precommande';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
    protected $guarded = [];

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }

    public function lineas()
    {
        return $this->hasMany(PreordenDetalle::class, 'fk_precommande', 'rowid');
    }

    public function extrafields()
    {
        return $this->hasOne(PreordenExtrafields::class, 'fk_object', 'rowid');
    }
}
