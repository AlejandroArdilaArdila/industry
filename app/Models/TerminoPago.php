<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminoPago extends Model
{
    protected $table = 'c_payment_term';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
}
