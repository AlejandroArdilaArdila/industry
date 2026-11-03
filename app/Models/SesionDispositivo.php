<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionDispositivo extends Model
{
    protected $table = 'integros_user_token';
    public $timestamps = false;
    protected $primaryKey = 'id';
}
