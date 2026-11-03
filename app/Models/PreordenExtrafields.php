<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreordenExtrafields extends Model
{
    protected $table = 'integros_precommande_extrafields';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
    protected $guarded = [];

    public function preorden()
    {
        return $this->belongsTo(Preorden::class, 'fk_object', 'rowid');
    }
}
