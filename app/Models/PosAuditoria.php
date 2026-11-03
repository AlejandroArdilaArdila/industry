<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosAuditoria extends Model
{
    protected $table = 'pos_auditoria';

    public $timestamps = false;

    protected $fillable = [
        'id_local',
        'fecha_evento',
        'modulo',
        'accion',
        'usuario_id',
        'usuario_nombre',
        'entidad_tipo',
        'entidad_id',
        'tipo_documento',
        'datos_antes_json',
        'datos_despues_json',
        'descripcion',
        'fk_caja_local',
        'total',
        'subtotal',
        'iva',
        'items',
    ];
}
