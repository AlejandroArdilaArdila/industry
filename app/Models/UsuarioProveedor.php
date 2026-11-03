<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Acceso de un contacto de proveedor (llx_integros_usuario_proveedor): un
 * contacto (socpeople) de un tercero proveedor (societe) que entra con el correo
 * del contacto, su clave (pass_crypted, hash estilo Dolibarr) y una api_key
 * cifrada (dolcrypt).
 */
class UsuarioProveedor extends Model
{
    protected $table = 'integros_usuario_proveedor';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    protected $hidden = ['pass_crypted', 'api_key'];

    protected function casts(): array
    {
        return [
            'debe_cambiar_clave' => 'boolean',
        ];
    }

    public function contacto()
    {
        return $this->belongsTo(Contacto::class, 'fk_socpeople', 'rowid');
    }

    public function proveedor()
    {
        return $this->belongsTo(Sociedad::class, 'fk_soc', 'rowid');
    }

    /**
     * Accesos habilitados: acceso activo, contacto activo y tercero
     * proveedor activo.
     */
    public function scopeHabilitados($query)
    {
        return $query
            ->where('estado', 1)
            ->whereHas('contacto', fn ($q) => $q->where('statut', 1))
            ->whereHas('proveedor', fn ($q) => $q->where('status', 1)->where('fournisseur', 1));
    }

    /**
     * Accesos habilitados del contacto con ese correo.
     */
    public function scopeHabilitadosPorCorreo($query, string $correo)
    {
        return $query
            ->habilitados()
            ->whereHas('contacto', fn ($q) => $q->where('email', $correo));
    }
}
