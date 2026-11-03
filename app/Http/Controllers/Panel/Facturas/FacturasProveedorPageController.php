<?php

namespace App\Http\Controllers\Panel\Facturas;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FacturasProveedorPageController extends Controller
{
    public function index(): View
    {
        return view('panel.facturas.proveedores', [
            'condicionesPago' => $this->condicionesPago(),
        ]);
    }

    /**
     * Catálogo de condiciones de pago (c_payment_term), para el dropdown de
     * filtro de la columna "Condición Pago".
     *
     * @return array<int, array<string, mixed>>
     */
    private function condicionesPago(): array
    {
        return DB::connection('mysql')
            ->table('c_payment_term')
            ->where('active', 1)
            ->orderBy('sortorder')
            ->get(['rowid', 'libelle'])
            ->map(fn ($row) => ['value' => (string) $row->rowid, 'text' => $row->libelle])
            ->values()
            ->all();
    }
}
