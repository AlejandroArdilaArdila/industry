<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    protected $table = 'const';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    /**
     * Trae solo los valores pedidos, con la entity 0 (global) sobreescrita
     * por la entity 1, igual que Conf::setValues() en Dolibarr.
     */
    public static function valores(array $nombres): array
    {
        return static::query()
            ->whereIn('name', $nombres)
            ->whereIn('entity', [0, 1])
            ->orderBy('entity')
            ->pluck('value', 'name')
            ->all();
    }
}
