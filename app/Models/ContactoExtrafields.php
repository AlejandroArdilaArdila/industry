<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactoExtrafields extends Model
{
    protected $table = 'socpeople_extrafields';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function contacto()
    {
        return $this->belongsTo(Contacto::class, 'fk_object', 'rowid');
    }
}
