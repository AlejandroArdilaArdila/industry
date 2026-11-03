<?php

namespace App\Http\Controllers\Panel\Perfil;

use App\Http\Controllers\Controller;
use App\Models\DolibarrUser;
use App\Services\Auth\AuthService;
use App\Services\Usuarios\PerfilUsuarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PerfilController extends Controller
{
    public function __construct(
        private PerfilUsuarioService $perfilService,
        private AuthService $authService,
    ) {
    }

    public function edit(): View
    {
        return view('panel.perfil.edit', $this->datosVista());
    }

    /** Variables de la vista de perfil (también la usa Configuración → Mi perfil). */
    public function datosVista(): array
    {
        /** @var DolibarrUser $usuario */
        $usuario = Auth::guard('web')->user();
        $esAdmin = $usuario->isAdmin();

        return [
            'usuario' => $usuario,
            'esAdmin' => $esAdmin,
            'formularioParaOtro' => false,
            'paises' => $this->perfilService->paises(),
            'departamentos' => $this->perfilService->departamentos(),
            'civilidades' => $this->perfilService->civilidades(),
            'extrafieldDefs' => $this->perfilService->extrafieldsDefiniciones(),
            'extrafieldValores' => $this->perfilService->valoresExtrafields($usuario),
            'opcionesExtrafield' => fn ($def) => $this->perfilService->opcionesExtrafield($def),
            'grupos' => $esAdmin ? $this->perfilService->grupos() : collect(),
            'gruposDe' => $esAdmin ? $this->perfilService->gruposDe($usuario) : [],
            'permisosAgrupados' => $esAdmin ? $this->perfilService->permisosAgrupados() : collect(),
            'permisosDe' => $esAdmin ? $this->perfilService->permisosDe($usuario) : [],
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var DolibarrUser $usuario */
        $usuario = Auth::guard('web')->user();
        $esAdmin = $usuario->isAdmin();

        $datos = $request->validate($this->perfilService->reglasDatosPersonales($esAdmin, $usuario->rowid));

        $this->perfilService->actualizarDatosPersonales($usuario, $datos, $esAdmin);
        $this->perfilService->actualizarExtrafields($usuario, $request->input('ef', []));

        return back()->with('status', 'Perfil actualizado correctamente.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var DolibarrUser $usuario */
        $usuario = Auth::guard('web')->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!$this->authService->verifyPassword($request->input('current_password'), $usuario)) {
            return back()->withErrors(['current_password' => 'La contraseña actual no es correcta.']);
        }

        $this->perfilService->cambiarPassword($usuario, $request->input('password'));

        return back()->with('status', 'Contraseña actualizada correctamente.');
    }
}
