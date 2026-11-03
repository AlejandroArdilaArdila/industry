<?php

namespace App\Http\Controllers\Panel\Facturas;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class FacturasPageController extends Controller
{
    public function index(): View
    {
        return view('panel.facturas.facturas');
    }
}
