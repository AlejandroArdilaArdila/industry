<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedioPago extends Model
{
    protected $table = 'c_paiement';
    public $timestamps = false;
    protected $primaryKey = 'id';
}
