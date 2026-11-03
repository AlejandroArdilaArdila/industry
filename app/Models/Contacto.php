<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contacto extends Model
{
    protected $table = 'socpeople';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }

    public function extrafields()
    {
        return $this->hasOne(ContactoExtrafields::class, 'fk_object', 'rowid');
    }
}
