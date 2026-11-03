<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Propuesta extends Model
{
    protected $table = 'propal';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'fk_user_author', 'rowid');
    }

    public function estado()
    {
        return $this->belongsTo(EstadoPropuesta::class, 'fk_statut', 'id');
    }
}
