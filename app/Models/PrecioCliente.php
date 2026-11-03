<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrecioCliente extends Model
{
    protected $table = 'societe_prices';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }
}
