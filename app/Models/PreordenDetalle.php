<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreordenDetalle extends Model
{
    protected $table = 'integros_precommandedet';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
    protected $guarded = [];

    public function preorden()
    {
        return $this->belongsTo(Preorden::class, 'fk_precommande', 'rowid');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'fk_product', 'rowid');
    }
}
