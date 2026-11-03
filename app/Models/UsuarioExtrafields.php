<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioExtrafields extends Model
{
    protected $table = 'user_extrafields';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'fk_object', 'rowid');
    }
}
