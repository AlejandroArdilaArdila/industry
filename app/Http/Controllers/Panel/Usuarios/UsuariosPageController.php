<?php

namespace App\Http\Controllers\Panel\Usuarios;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UsuariosPageController extends Controller
{
    public function index(): View
    {
        return view('panel.usuarios.usuarios', [
            'grupos' => $this->grupos(),
            'esAdmin' => (bool) Auth::guard('web')->user()?->isAdmin(),
        ]);
    }

    /**
     * Grupos de usuario (usergroup), para el dropdown multiselect del grid.
     * A diferencia de las categorías de Terceros, usergroup no tiene color
     * propio: el dropdown ya cae a un gris por defecto cuando falta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function grupos(): array
    {
        return DB::connection('mysql')
            ->table('usergroup')
            ->orderBy('nom')
            ->get(['rowid', 'nom'])
            ->map(fn ($row) => [
                'rowid' => (int) $row->rowid,
                'label' => $row->nom,
                'color' => null,
            ])
            ->values()
            ->all();
    }
}
