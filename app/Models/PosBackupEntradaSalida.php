<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosBackupEntradaSalida extends Model
{
    protected $table = 'pos_backup_entradas_salidas';
    public $timestamps = false;
    protected $primaryKey = 'ref_local';
    protected $keyType = 'string';
    public $incrementing = false;
}
