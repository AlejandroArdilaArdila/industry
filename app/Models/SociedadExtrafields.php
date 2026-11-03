<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SociedadExtrafields extends Model
{
    protected $table = 'societe_extrafields';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_object', 'rowid');
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class, 'fk_depto', 'rowid');
    }
}
