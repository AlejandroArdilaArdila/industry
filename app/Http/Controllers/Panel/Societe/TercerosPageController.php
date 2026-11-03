<?php

namespace App\Http\Controllers\Panel\Societe;

use App\Http\Controllers\Controller;
use App\Services\Societe\TerceroFichaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TercerosPageController extends Controller
{
    public function index(TerceroFichaService $fichaService): View
    {
        return view('panel.societe.terceros', [
            'puedeCrear' => $fichaService->puedeEditar(Auth::guard('web')->user()),
            'categoriasCliente' => $this->categorias('categorie_societe'),
            'categoriasProveedor' => $this->categorias('categorie_fournisseur'),
        ]);
    }

    /**
     * Categorías de cliente/proveedor con su color, para el dropdown
     * multiselect del grid (igual que list_societeAG.php).
     *
     * @return array<int, array<string, mixed>>
     */
    private function categorias(string $tablaRelacion): array
    {
        return DB::connection('mysql')
            ->table("{$tablaRelacion} as rel")
            ->leftJoin('categorie as cat', 'cat.rowid', '=', 'rel.fk_categorie')
            ->groupBy('rel.fk_categorie', 'cat.rowid', 'cat.fk_parent', 'cat.label', 'cat.color')
            ->get(['cat.rowid', 'cat.fk_parent', 'cat.label', 'cat.color'])
            ->map(fn ($row) => [
                'rowid' => (int) $row->rowid,
                'fk_parent' => (int) $row->fk_parent,
                'label' => $row->label,
                'color' => $row->color,
            ])
            ->values()
            ->all();
    }
}
