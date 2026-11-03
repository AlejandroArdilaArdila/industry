<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrupoUsuario extends Model
{
    protected $table = 'usergroup';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, 'usergroup_rights', 'fk_usergroup', 'fk_id');
    }

    public function permisosApk()
    {
        return $this->belongsToMany(Permiso::class, 'usergroup_rights', 'fk_usergroup', 'fk_id');
    }
}
