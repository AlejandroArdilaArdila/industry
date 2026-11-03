<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    protected $table = 'integrospos_turnos';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
}
