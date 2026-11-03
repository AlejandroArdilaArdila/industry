<?php

namespace App\Services\Panel;

use App\Models\DolibarrUser;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de permisos de Dolibarr (rights_def.id) para el panel: un admin
 * los tiene todos; si no, cuenta el permiso directo o el heredado de un grupo.
 */
class PermisosDolibarr
{
    public const TERCEROS_CREAR = 122;
    public const PRODUCTOS_CREAR = 32;
    public const SERVICIOS_CREAR = 532;
    public const CATEGORIAS_CREAR = 242;
    public const CATEGORIAS_ELIMINAR = 243;

    public function tiene(?DolibarrUser $usuario, int $permiso): bool
    {
        if (!$usuario) {
            return false;
        }
        if ($usuario->isAdmin()) {
            return true;
        }

        $db = DB::connection('mysql');

        $directo = $db->table('user_rights')
            ->where('fk_user', $usuario->rowid)
            ->where('fk_id', $permiso)
            ->exists();

        return $directo || $db->table('usergroup_rights as gr')
            ->join('usergroup_user as gu', 'gu.fk_usergroup', '=', 'gr.fk_usergroup')
            ->where('gu.fk_user', $usuario->rowid)
            ->where('gr.fk_id', $permiso)
            ->exists();
    }
}
