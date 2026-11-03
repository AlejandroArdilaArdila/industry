<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class DolibarrUser extends Authenticatable
{
    use HasApiTokens;

    /**
     * Conexión a la BD de Dolibarr
     */
    protected $connection = 'mysql';

    /**
     * Tabla
     */
    protected $table = 'user';

    /**
     * Llave primaria
     */
    protected $primaryKey = 'rowid';

    /**
     * La PK es autoincremental
     */
    public $incrementing = true;

    /**
     * Tipo de PK
     */
    protected $keyType = 'int';

    /**
     * Dolibarr no usa created_at ni updated_at
     */
    public $timestamps = false;

    /**
     * Campos asignables
     */
    protected $fillable = [
        'login',
        'firstname',
        'lastname',
        'email',
        'employee',
        'admin',
        'statut',
        'entity',
        'job',
        'office_phone',
        'user_mobile',
        'address',
        'zip',
        'town',
        'fk_state',
        'fk_country',
        'birth',
        'civility',
        'gender',
    ];

    /**
     * Campos ocultos
     */
    protected $hidden = [
        'pass',
        'pass_crypted',
        'api_key'
    ];

    /**
     * Nombre completo
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    /**
     * Usuario activo
     */
    public function isActive(): bool
    {
        return $this->statut == 1;
    }

    /**
     * Administrador
     */
    public function isAdmin(): bool
    {
        return $this->admin == 1;
    }

    /**
     * Campo usado para autenticación
     */
    public function getAuthIdentifierName()
    {
        return 'login';
    }

    /**
     * Hash de contraseña
     */
    public function getAuthPassword()
    {
        return $this->pass_crypted;
    }

    /**
     * Scope usuarios activos
     */
    public function scopeActive($query)
    {
        return $query
            ->where('entity', 1)
            ->where('statut', 1);
    }
}