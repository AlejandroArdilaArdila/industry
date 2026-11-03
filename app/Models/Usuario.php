<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    protected $table = 'user';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function extrafields()
    {
        return $this->hasOne(UsuarioExtrafields::class, 'fk_object', 'rowid');
    }

    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, 'user_rights', 'fk_user', 'fk_id');
    }

    public function permisosApk()
    {
        return $this->belongsToMany(Permiso::class, 'user_rights', 'fk_user', 'fk_id');
    }

    public function grupos()
    {
        return $this->belongsToMany(GrupoUsuario::class, 'usergroup_user', 'fk_user', 'fk_usergroup');
    }

    public function terminal()
    {
        return $this->hasOne(TerminalPos::class, 'fk_soc', 'rowid');
    }

    /**
     * Terceros (societe) asignados a este usuario como vendedor, via
     * societe_commerciaux.fk_user. Sin filtrar por client/fournisseur.
     */
    public function terceros()
    {
        return $this->hasManyThrough(
            Sociedad::class,
            SociedadComercial::class,
            'fk_user',
            'rowid',
            'rowid',
            'fk_soc'
        );
    }

    public function tieneDerecho(string $perms, string $subperms): bool
    {
        $tienePermisoDirecto = $this->permisos->contains(
            fn (Permiso $permiso) => $permiso->perms === $perms && $permiso->subperms === $subperms
        );

        if ($tienePermisoDirecto) {
            return true;
        }

        return $this->grupos->contains(
            fn (GrupoUsuario $grupo) => $grupo->permisos->contains(
                fn (Permiso $permiso) => $permiso->perms === $perms && $permiso->subperms === $subperms
            )
        );
    }
}
