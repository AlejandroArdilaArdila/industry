<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogErrorEndpoint extends Model
{
    protected $table = 'pos_logs_errores';

    protected $fillable = [
        'deviceid',
        'fk_user',
        'metodo',
        'url',
        'status_code',
        'tiempo_ms',
        'request_body',
        'response_body',
        'fecha_cliente',
    ];
}
