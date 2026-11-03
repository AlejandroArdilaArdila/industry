<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SociedadCategoria extends Model
{
    protected $table = 'categorie_societe';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'fk_soc';

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }
}
