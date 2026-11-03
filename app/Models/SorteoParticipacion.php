<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SorteoParticipacion extends Model
{
    protected $table = 'pos_sorteo_participacion';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function sorteo()
    {
        return $this->belongsTo(Sorteo::class, 'fk_sorteo', 'rowid');
    }
}
