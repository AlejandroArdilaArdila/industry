<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promocion extends Model
{
    protected $table = 'integros_promociones';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    public function productos()
    {
        return $this->hasMany(PromocionProducto::class, 'fk_prom', 'rowid');
    }

    public function clientes()
    {
        return $this->hasMany(PromocionCliente::class, 'fk_prom', 'rowid');
    }

    public function categorias()
    {
        return $this->hasMany(PromocionCategoria::class, 'fk_prom', 'rowid');
    }
}
