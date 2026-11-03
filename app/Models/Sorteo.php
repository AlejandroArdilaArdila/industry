<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sorteo extends Model
{
    protected $table = 'pos_sorteo';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
}
