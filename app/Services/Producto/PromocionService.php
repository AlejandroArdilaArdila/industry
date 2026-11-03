<?php

namespace App\Services\Producto;

use App\Models\PromocionProducto;
use Illuminate\Support\Collection;

class PromocionService
{
    private const COLUMNAS_PROMO = [
        'lip.rowid as promo_id', 'lip.code', 'lip.description', 'lip.priority',
        'lip.discount_type', 'lip.discount_type_promotion', 'lip.buy_quantity', 'lip.discount',
        'integros_promociones_products.rowid as pp_rowid', 'integros_promociones_products.fk_product',
        'integros_promociones_products.fk_producto_regalo', 'integros_promociones_products.cantidad_compra',
        'integros_promociones_products.cantidad_regalo', 'integros_promociones_products.descuento as pp_descuento',
    ];

    /**
     * Resuelve, para cada producto, la promoción ganadora (exclusiva > categoría > general).
     * Devuelve [productId => promoArray|null]. Todo por lote, sin consultas por producto.
     */
    public function resolverParaProductos(array $productIds, ?int $clienteId): array
    {
        if (empty($productIds)) {
            return [];
        }

        $generales = $this->promosGenerales($productIds);
        $categoria = $clienteId ? $this->promosCategoria($productIds, $clienteId) : collect();
        $exclusiva = $clienteId ? $this->promosExclusiva($productIds, $clienteId) : collect();

        $resultado = [];
        foreach ($productIds as $productId) {
            $promoExclusiva = $this->seleccionarMejorPromo(($exclusiva->get($productId) ?? collect())->all());
            $promoCategoria = $this->seleccionarMejorPromo(($categoria->get($productId) ?? collect())->all());
            $promoGeneral = $this->seleccionarMejorPromo(($generales->get($productId) ?? collect())->all());

            $resultado[$productId] = $promoExclusiva ?? $promoCategoria ?? $promoGeneral;
        }

        return $resultado;
    }

    public function calcularPrecioConPromocion(int $precioTotal, ?array $promo): int
    {
        if (empty($promo) || (int) $promo['discount_type'] !== 1) {
            return $precioTotal;
        }

        // discount_type_promotion = 3 (monto fijo, o monto variable ya normalizado
        // en mapearFilaPromo): el descuento es en pesos, no porcentaje.
        if ((int) $promo['discount_type_promotion'] === 3) {
            return (int) max(0, $precioTotal - $promo['discount']);
        }

        $descuento = ($precioTotal * $promo['discount']) / 100;

        return (int) ($precioTotal - $descuento);
    }

    private function promosGenerales(array $productIds): Collection
    {
        $hoy = now()->toDateString();

        return PromocionProducto::query()
            ->join('integros_promociones as lip', 'lip.rowid', '=', 'integros_promociones_products.fk_prom')
            ->leftJoin('integros_promociones_customers as lipc', 'lipc.fk_prom', '=', 'lip.rowid')
            ->leftJoin('integros_promociones_categories as lipcat', 'lipcat.fk_prom', '=', 'lip.rowid')
            ->whereIn('integros_promociones_products.fk_product', $productIds)
            ->where('lip.status', 2)
            ->where(fn ($q) => $q->whereNull('lip.date_start')->orWhereDate('lip.date_start', '<=', $hoy))
            ->where(fn ($q) => $q->whereNull('lip.date_end')->orWhereDate('lip.date_end', '>=', $hoy))
            ->whereNull('lipc.rowid')
            ->whereNull('lipcat.rowid')
            ->select(self::COLUMNAS_PROMO)
            ->get()
            ->map(fn ($row) => $this->mapearFilaPromo($row, 'general'))
            ->groupBy('fk_product');
    }

    private function promosCategoria(array $productIds, int $clienteId): Collection
    {
        $hoy = now()->toDateString();

        return PromocionProducto::query()
            ->join('integros_promociones as lip', 'lip.rowid', '=', 'integros_promociones_products.fk_prom')
            ->join('integros_promociones_categories as lipcat', 'lipcat.fk_prom', '=', 'lip.rowid')
            ->join('categorie_societe as cs', 'cs.fk_categorie', '=', 'lipcat.fk_category')
            ->whereIn('integros_promociones_products.fk_product', $productIds)
            ->where('cs.fk_soc', $clienteId)
            ->where('lip.status', 2)
            ->where(fn ($q) => $q->whereNull('lip.date_start')->orWhereDate('lip.date_start', '<=', $hoy))
            ->where(fn ($q) => $q->whereNull('lip.date_end')->orWhereDate('lip.date_end', '>=', $hoy))
            ->select(self::COLUMNAS_PROMO)
            ->get()
            ->map(fn ($row) => $this->mapearFilaPromo($row, 'categoria'))
            ->groupBy('fk_product');
    }

    private function promosExclusiva(array $productIds, int $clienteId): Collection
    {
        $hoy = now()->toDateString();

        return PromocionProducto::query()
            ->join('integros_promociones as lip', 'lip.rowid', '=', 'integros_promociones_products.fk_prom')
            ->join('integros_promociones_customers as lipc', 'lipc.fk_prom', '=', 'lip.rowid')
            ->whereIn('integros_promociones_products.fk_product', $productIds)
            ->where('lipc.fk_soc', $clienteId)
            ->where('lip.status', 2)
            ->where(fn ($q) => $q->whereNull('lip.date_start')->orWhereDate('lip.date_start', '<=', $hoy))
            ->where(fn ($q) => $q->whereNull('lip.date_end')->orWhereDate('lip.date_end', '>=', $hoy))
            ->select(self::COLUMNAS_PROMO)
            ->get()
            ->map(fn ($row) => $this->mapearFilaPromo($row, 'exclusiva'))
            ->groupBy('fk_product');
    }

    private function mapearFilaPromo($row, string $tipo): array
    {
        // Comercial "monto variable" (discount_type_promotion = 4): el descuento en
        // pesos es por producto (integros_promociones_products.descuento). Se
        // normaliza a monto fijo (3) con ese valor, igual que el legado
        // (integrospos/class/promociones.class.php::_mapearFilaPromo).
        $discountTypePromotion = (int) $row->discount_type_promotion;
        $discount = (float) $row->discount;
        if ((int) $row->discount_type === 1 && $discountTypePromotion === 4) {
            $discountTypePromotion = 3;
            $discount = (float) ($row->pp_descuento ?? 0);
        }

        return [
            'promo_id' => (int) $row->promo_id,
            'code' => $row->code,
            'description' => $row->description,
            'priority' => (int) $row->priority,
            'discount_type' => (int) $row->discount_type,
            'discount_type_promotion' => $discountTypePromotion,
            'buy_quantity' => (int) $row->buy_quantity,
            'discount' => $discount,
            'pp_rowid' => (int) $row->pp_rowid,
            'fk_product' => (int) $row->fk_product,
            'fk_producto_regalo' => (int) $row->fk_producto_regalo,
            'cantidad_compra' => (int) $row->cantidad_compra,
            'cantidad_regalo' => (int) $row->cantidad_regalo,
            'tipo' => $tipo,
        ];
    }

    private function seleccionarMejorPromo(array $promos): ?array
    {
        if (empty($promos)) {
            return null;
        }

        $porTipo = [];
        foreach ($promos as $p) {
            $porTipo[$p['discount_type']][] = $p;
        }

        if (count($porTipo) === 1) {
            return $this->seleccionarPorPrioridad(reset($porTipo));
        }

        $maxPromoId = -1;
        $tipoDelMaximo = null;
        foreach ($promos as $p) {
            if ($p['promo_id'] > $maxPromoId) {
                $maxPromoId = $p['promo_id'];
                $tipoDelMaximo = $p['discount_type'];
            }
        }

        return $this->seleccionarPorPrioridad($porTipo[$tipoDelMaximo]);
    }

    private function seleccionarPorPrioridad(array $promos): ?array
    {
        if (empty($promos)) {
            return null;
        }

        $conPrioridad1 = array_filter($promos, fn ($p) => (int) $p['priority'] === 1);
        $candidatas = !empty($conPrioridad1) ? $conPrioridad1 : $promos;

        if (empty($conPrioridad1)) {
            usort($candidatas, function ($a, $b) {
                $pa = (int) $a['priority'];
                $pb = (int) $b['priority'];
                if ($pa !== $pb) {
                    return $pa - $pb;
                }

                return $b['promo_id'] - $a['promo_id'];
            });
        }

        $ganadora = null;
        foreach ($candidatas as $p) {
            if ($ganadora === null || $p['promo_id'] > $ganadora['promo_id']) {
                $ganadora = $p;
            }
        }

        return $ganadora;
    }
}
