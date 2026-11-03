<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaBancaria extends Model
{
    protected $table = 'bank_account';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function categorias()
    {
        return $this->belongsToMany(Categoria::class, 'categorie_account', 'fk_account', 'fk_categorie');
    }
}
