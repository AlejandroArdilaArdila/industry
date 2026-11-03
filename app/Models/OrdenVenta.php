<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenVenta extends Model
{
    protected $table = 'commande';
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
}
