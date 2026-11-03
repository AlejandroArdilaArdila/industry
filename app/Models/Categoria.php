<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $table = 'categorie';
    public $timestamps = false;
    protected $primaryKey = 'rowid';

    /**
     * Categorias de producto (type=0) de los productos dados, con el id del
     * producto en "id_producto". Ordenadas por nombre.
     *
     * @param  array<int, int>  $idsProducto
     */
    public function scopeDeProductos($query, array $idsProducto)
    {
        return $query
            ->join('categorie_product', 'categorie_product.fk_categorie', '=', 'categorie.rowid')
            ->where('categorie.type', 0)
            ->whereIn('categorie_product.fk_product', $idsProducto)
            ->orderBy('categorie.label')
            ->select('categorie_product.fk_product as id_producto', 'categorie.rowid as id', 'categorie.label as nombre');
    }

    public function productos()
    {
        return $this->hasMany(ProductoCategoria::class, 'fk_categorie', 'rowid');
    }

    /**
     * Ids iniciales + todas sus categorias hijas, recursivo (BFS por
     * fk_parent). Misma logica que ConfiguracionService::categoriasPos(),
     * generalizada para arrancar desde cualquier conjunto de ids.
     *
     * @param  array<int, int>  $idsIniciales
     * @return array<int, int>
     */
    public static function idsConDescendientes(array $idsIniciales): array
    {
        $ids = array_values(array_filter($idsIniciales));
        $pendientes = $ids;

        while (!empty($pendientes)) {
            $hijas = static::query()->whereIn('fk_parent', $pendientes)->pluck('rowid')->all();
            $pendientes = [];

            foreach ($hijas as $idHija) {
                if (!in_array($idHija, $ids, true)) {
                    $ids[] = $idHija;
                    $pendientes[] = $idHija;
                }
            }
        }

        return $ids;
    }
}
