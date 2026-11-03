<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlantillaTicket extends Model
{
    protected $table = 'integrospos_ticket_template';
    public $timestamps = false;
    protected $primaryKey = 'rowid';
}
