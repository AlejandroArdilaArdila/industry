<?php

namespace App\Services\Categorias;

use App\Models\Categoria;
use App\Models\Usuario;

class CategoriaService
{
    /**
     * Categorias de producto activas (type=0) del ERP, con el total de
     * productos asociados a cada una. Replica getCategorias()
     * (api_productos.class.php, modulo integrosapk).
     */
    public function listar(Usuario $usuario): array
    {
        if (!$usuario->tieneDerecho('lire', '')) {
            abort(401, 'Sin permisos');
        }

        return Categoria::query()
            ->where('type', 0)
            ->where('entity', 1)
            ->withCount('productos')
            ->orderBy('label')
            ->get(['rowid', 'label', 'description', 'color', 'fk_parent'])
            ->map(fn ($categoria) => [
                'rowid' => (int) $categoria->rowid,
                'label' => $categoria->label,
                'description' => $categoria->description,
                'color' => $categoria->color,
                'fk_parent' => (int) $categoria->fk_parent,
                'total_productos' => (int) $categoria->productos_count,
            ])
            ->values()
            ->all();
    }
}
