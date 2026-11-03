<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosBackupPedido extends Model
{
    protected $table = 'pos_backup_pedidos';
    public $timestamps = false;
    protected $primaryKey = 'ref_local';
    protected $keyType = 'string';
    public $incrementing = false;
}
