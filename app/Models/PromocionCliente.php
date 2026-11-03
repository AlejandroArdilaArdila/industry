<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromocionCliente extends Model
{
    protected $table = 'integros_promociones_customers';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function promocion()
    {
        return $this->belongsTo(Promocion::class, 'fk_prom', 'rowid');
    }

    public function sociedad()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }
}
