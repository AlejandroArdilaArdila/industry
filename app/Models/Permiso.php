<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    protected $table = 'rights_def';
    public $timestamps = false;
    protected $primaryKey = 'id';
}
