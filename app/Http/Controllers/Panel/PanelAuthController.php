<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\DolibarrUser;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class PanelAuthController extends Controller
{
    public function __construct(private AuthService $authService)
    {
    }

    public function showLogin(): View
    {
        return view('panel.auth.login');
    }

    /**
     * Autentica contra la tabla `user` de Dolibarr con las mismas reglas de
     * contraseña que usa la API (AuthService::verifyPassword), pero abre
     * una sesión Laravel (guard 'web') en vez de emitir un token Sanctum.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        /** @var DolibarrUser|null $user */
        $user = DolibarrUser::query()
            ->where('login', $credentials['login'])
            ->whereIn('entity', [0, 1])
            ->first();

        $valido = $user
            && $this->authService->verifyPassword($credentials['password'], $user)
            && (int) $user->statut === 1;

        if (!$valido) {
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => 'Credenciales inválidas o usuario inactivo.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('panel.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::route('login');
    }
}
