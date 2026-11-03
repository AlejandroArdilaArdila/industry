<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoWss extends Model
{
    protected $table = 'integrospos_wss_events';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
}
