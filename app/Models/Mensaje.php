<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mensaje extends Model
{
    protected $table = 'chat_vendedor';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function emisor()
    {
        return $this->belongsTo(Usuario::class, 'fk_user_from', 'rowid');
    }

    public function receptor()
    {
        return $this->belongsTo(Usuario::class, 'fk_user_to', 'rowid');
    }
}
