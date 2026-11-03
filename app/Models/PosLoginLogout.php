<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosLoginLogout extends Model
{
    protected $table = 'pos_login_logout';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
}
