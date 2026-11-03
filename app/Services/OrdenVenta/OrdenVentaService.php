<?php

namespace App\Services\OrdenVenta;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Crea una Orden Comercial de Dolibarr (modulo Commande: llx_commande /
 * llx_commandedet) EN BORRADOR (fk_statut=0, ref "(PROVxxx)"): nunca se
 * llama al paso de validacion del modulo, que es lo que en Dolibarr asigna
 * la numeracion definitiva y dispara los efectos de stock/contabilidad.
 *
 * A diferencia de FacturacionService::validarFacturaPos (sp_pos_crear_ticket_v1),
 * ESTE endpoint no resuelve niveles de precio ni impuestos extra (IBUA/ICL/
 * ADV/ICUI) en el servidor: los precios/impuestos de cada linea vienen ya
 * calculados desde el cliente (mismo shape de "lines" que ticket/payment) y
 * se insertan tal cual. No existe todavia un stored procedure para ordenes.
 */
class OrdenVentaService
{
    private const CONNECTION = 'mysql';

    public function crear(Request $request, Usuario $usuario): array
    {
        $customerId = (int) $request->input('customerId', 0);
        if ($customerId <= 0) {
            abort(401, "El campo 'customerId' es obligatorio.");
        }

        $employeeId = (int) $request->input('employeeId', 0);
        if ($employeeId <= 0) {
            abort(401, "El campo 'employeeId' es obligatorio.");
        }

        $lineas = $request->input('lines');
        if (! is_array($lineas) || count($lineas) === 0) {
            abort(401, "El campo 'lines' es obligatorio y debe ser un arreglo con al menos un elemento.");
        }

        $db = DB::connection(self::CONNECTION);

        if (! $db->table('societe')->where('rowid', $customerId)->exists()) {
            abort(401, "El cliente con id [{$customerId}] no existe.");
        }

        $productIds = collect($lineas)
            ->pluck('idProduct')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        $productos = $db->table('product')
            ->whereIn('rowid', $productIds)
            ->pluck('fk_product_type', 'rowid');

        foreach ($productIds as $idProduct) {
            if (! $productos->has($idProduct)) {
                abort(401, "El producto con id [{$idProduct}] no existe.");
            }
        }

        $moneda = $db->table('const')
            ->where('name', 'MAIN_MONNAIE')
            ->where('entity', 1)
            ->value('value') ?: 'COP';

        $refLocal = trim((string) $request->input('refLocal', ''));
        $notaPrivada = $request->input('notaPrivada') ?? $request->input('note');
        $fechaOrden = $request->filled('fecha') ? (string) $request->input('fecha') : now()->format('Y-m-d');

        return $db->transaction(function () use ($db, $customerId, $employeeId, $lineas, $productos, $moneda, $refLocal, $notaPrivada, $fechaOrden) {
            $now = now()->format('Y-m-d H:i:s');

            $idOrden = $db->table('commande')->insertGetId([
                'ref' => '(PROVTMP'.uniqid().')',
                'entity' => 1,
                'fk_soc' => $customerId,
                'ref_client' => $refLocal !== '' ? $refLocal : null,
                'date_creation' => $now,
                'date_commande' => $fechaOrden,
                'fk_user_author' => $employeeId,
                'fk_statut' => 0,
                'note_private' => $notaPrivada,
                'total_ht' => 0,
                'total_tva' => 0,
                'total_ttc' => 0,
                'multicurrency_code' => $moneda,
                'multicurrency_tx' => 1,
            ]);

            $ref = "(PROV{$idOrden})";
            $db->table('commande')->where('rowid', $idOrden)->update(['ref' => $ref]);

            $totalHt = 0.0;
            $totalTva = 0.0;
            $totalTtc = 0.0;
            $rang = 1;

            foreach ($lineas as $linea) {
                $idProduct = (int) ($linea['idProduct'] ?? 0);
                $cant = (float) ($linea['cant'] ?? 0);
                $subprice = isset($linea['nivelPrecioSinIva']) ? (float) $linea['nivelPrecioSinIva'] : (float) ($linea['precio'] ?? 0);
                $subpriceTtc = isset($linea['nivelPrecioConIva']) ? (float) $linea['nivelPrecioConIva'] : $subprice;
                $tvaTx = (float) ($linea['tva_tx'] ?? 0);
                $remisePercent = (float) ($linea['discount'] ?? 0);

                $lineTotalHt = round($subprice * $cant, 2);
                $lineTotalTtc = round($subpriceTtc * $cant, 2);
                $lineTotalTva = round($lineTotalTtc - $lineTotalHt, 2);

                $db->table('commandedet')->insert([
                    'fk_commande' => $idOrden,
                    'fk_product' => $idProduct,
                    'description' => $linea['description'] ?? $linea['note'] ?? null,
                    'qty' => $cant,
                    'tva_tx' => $tvaTx,
                    'remise_percent' => $remisePercent,
                    'price' => $subprice,
                    'subprice' => $subprice,
                    'subprice_ttc' => $subpriceTtc,
                    'total_ht' => $lineTotalHt,
                    'total_tva' => $lineTotalTva,
                    'total_ttc' => $lineTotalTtc,
                    'product_type' => $productos[$idProduct] ?? 0,
                    'rang' => $rang++,
                    'multicurrency_code' => $moneda,
                    'multicurrency_subprice' => $subprice,
                    'multicurrency_subprice_ttc' => $subpriceTtc,
                    'multicurrency_total_ht' => $lineTotalHt,
                    'multicurrency_total_tva' => $lineTotalTva,
                    'multicurrency_total_ttc' => $lineTotalTtc,
                ]);

                $totalHt += $lineTotalHt;
                $totalTva += $lineTotalTva;
                $totalTtc += $lineTotalTtc;
            }

            $db->table('commande')->where('rowid', $idOrden)->update([
                'total_ht' => $totalHt,
                'total_tva' => $totalTva,
                'total_ttc' => $totalTtc,
                'multicurrency_total_ht' => $totalHt,
                'multicurrency_total_tva' => $totalTva,
                'multicurrency_total_ttc' => $totalTtc,
            ]);

            return [
                'success' => true,
                'idOrden' => $idOrden,
                'ref' => $ref,
                'fk_statut' => 0,
                'total_ht' => $totalHt,
                'total_tva' => $totalTva,
                'total_ttc' => $totalTtc,
            ];
        });
    }
}
