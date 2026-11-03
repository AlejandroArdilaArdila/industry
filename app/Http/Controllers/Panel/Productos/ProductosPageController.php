<?php

namespace App\Http\Controllers\Panel\Productos;

use App\Http\Controllers\Controller;
use App\Services\Productos\ProductoFichaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductosPageController extends Controller
{
    public function __construct(private ProductoFichaService $fichaService)
    {
    }

    public function index(): View
    {
        return view('panel.productos.productos', [
            'puedeCrear' => $this->fichaService->puedeEditar(Auth::guard('web')->user(), ProductoFichaService::TIPO_PRODUCTO),
            'categorias' => $this->categorias(),
            'type' => 0,
            'gridId' => 'GridProductos',
        ]);
    }

    public function servicios(): View
    {
        return view('panel.productos.servicios', [
            'puedeCrear' => $this->fichaService->puedeEditar(Auth::guard('web')->user(), ProductoFichaService::TIPO_SERVICIO),
            'categorias' => $this->categorias(),
            'type' => 1,
            'gridId' => 'GridServicios',
        ]);
    }

    /**
     * Categorías de producto (Dolibarr type=0, aplica tanto a productos
     * como a servicios ya que ambos viven en la tabla `product`), para el
     * dropdown multiselect del grid.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categorias(): array
    {
        return DB::connection('mysql')
            ->table('categorie')
            ->where('type', 0)
            ->whereNotNull('label')
            ->where('label', '<>', '')
            ->get(['rowid', 'label', 'color'])
            ->map(fn ($row) => [
                'rowid' => (int) $row->rowid,
                'label' => $row->label,
                'color' => $row->color,
            ])
            ->values()
            ->all();
    }
}
