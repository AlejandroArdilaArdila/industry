<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromocionCategoria extends Model
{
    protected $table = 'integros_promociones_categories';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function promocion()
    {
        return $this->belongsTo(Promocion::class, 'fk_prom', 'rowid');
    }
}
