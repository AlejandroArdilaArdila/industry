<?php

namespace App\Http\Controllers\Panel\Categorias;

use App\Http\Controllers\Controller;
use App\Services\Categorias\CategoriasService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CategoriasPageController extends Controller
{
    public function __construct(private CategoriasService $categorias)
    {
    }

    public function index(): View
    {
        return view('panel.categorias.categorias', [
            'tipos' => $this->categorias->tipos(),
            'puedeCrear' => $this->categorias->puedeEditar(Auth::guard('web')->user()),
        ]);
    }
}
