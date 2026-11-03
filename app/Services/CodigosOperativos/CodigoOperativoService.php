<?php

namespace App\Services\CodigosOperativos;

use App\Models\CodigoOperativo;

class CodigoOperativoService
{
    /**
     * Codigos operativos activos y su producto/servicio asociado. Replica
     * listarCodigosOperativos() (custom/integrospos/backend/codigosoperativos/ajax_codigos_operativos.php).
     */
    public function listar(): array
    {
        return CodigoOperativo::query()
            ->where('entity', 1)
            ->where('active', 1)
            ->with('producto:rowid,ref,label,fk_product_type')
            ->orderByDesc('rowid')
            ->get(['rowid', 'codigo', 'fk_product', 'descuento', 'date_creation'])
            ->map(fn ($codigoOperativo) => [
                'rowid' => (int) $codigoOperativo->rowid,
                'codigo' => $codigoOperativo->codigo,
                'fk_product' => (int) $codigoOperativo->fk_product,
                'descuento' => is_null($codigoOperativo->descuento) ? null : (int) $codigoOperativo->descuento,
                'ref' => $codigoOperativo->producto?->ref,
                'label' => $codigoOperativo->producto?->label,
                'tipo' => (int) $codigoOperativo->producto?->fk_product_type === 1 ? 'Servicio' : 'Producto',
                'date_creation' => $codigoOperativo->date_creation,
            ])
            ->values()
            ->all();
    }
}
