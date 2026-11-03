<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosBackupNotaCredito extends Model
{
    protected $table = 'pos_backup_notas_credito';
    public $timestamps = false;
    protected $primaryKey = 'ref_local';
    protected $keyType = 'string';
    public $incrementing = false;
}
