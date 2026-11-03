Objetivo de esta fase
Login funcional en /panel/login que autentica contra la tabla user de Dolibarr (mismas reglas de contraseña que la API: bcrypt/MD5/plano vía AuthService::verifyPassword), abre sesión Laravel (guard web), y redirige a un dashboard placeholder protegido — sirve de esqueleto para las páginas que se irán agregando después.

Cero cambios en routes/api.php, AuthController, ni en el comportamiento de los guards api/apikey existentes. Todo lo nuevo vive en rutas, controladores y vistas propias del panel.

Cambios
1. Guard de sesión nuevo (config/auth.php)
Agregar, sin tocar defaults (sigue siendo api) ni los guards existentes:

'web' => [
    'driver' => 'session',
    'provider' => 'api', // reutiliza el mismo provider -> DolibarrUser
],
routes/web.php ya recibe el middleware group web (sesión, CSRF) por defecto vía bootstrap/app.php (withRouting(web: ...)), así que no hace falta tocar bootstrap/app.php.

2. Rutas del panel (routes/web.php)
Reemplazar el contenido actual (la ruta / de bienvenida se puede dejar o quitar — se conserva para no romper nada, el panel vive bajo /panel):

Route::prefix('panel')->name('panel.')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Route::get('/login', [PanelAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [PanelAuthController::class, 'login'])->name('login.submit');
    });

    Route::middleware('auth:web')->group(function () {
        Route::post('/logout', [PanelAuthController::class, 'logout'])->name('logout');
        Route::get('/', [PanelDashboardController::class, 'index'])->name('dashboard');
    });
});
3. Controladores nuevos
app/Http/Controllers/Panel/PanelAuthController.php
showLogin(): retorna panel.auth.login.
login(Request $request): valida login/password, busca DolibarrUser::query()->where('login', $login)->whereIn('entity', [0,1])->first() (mismo criterio que AuthService::login), verifica con app(AuthService::class)->verifyPassword($password, $user) (método ya público, se reutiliza tal cual — no se duplica lógica de hashing), chequea $user->statut == 1. Si todo ok: Auth::guard('web')->login($user), $request->session()->regenerate(), redirect a panel.dashboard. Si falla: vuelve a la vista con un error genérico (no filtrar cuál de las dos condiciones falló, mismo criterio de seguridad que la API).
logout(Request $request): Auth::guard('web')->logout() + session()->invalidate() + session()->regenerateToken().
app/Http/Controllers/Panel/PanelDashboardController.php
index(): retorna panel.dashboard con el usuario autenticado (Auth::guard('web')->user()), solo para confirmar visualmente que el login/redirect/sesión funcionan.
No se toca AuthService ni AuthController — se reutiliza verifyPassword tal como está.

4. Vistas Blade
resources/views/panel/layouts/app.blade.php: layout base con @vite(['resources/css/app.css','resources/js/app.js']), bloque @yield('content').
resources/views/panel/auth/login.blade.php: formulario login/password + @csrf, muestra error de sesión si viene.
resources/views/panel/dashboard.blade.php: placeholder con nombre del usuario (Auth::guard('web')->user()->full_name) y botón de logout.
5. Frontend (Vite, sin migrar nada)
resources/css/app.css: ya tiene Tailwind 4 importado, se usa tal cual para las clases del login/dashboard.
resources/js/app.js: hoy vacío; se deja como entrypoint para JS futuro del panel (por ahora no necesita lógica, el login es un POST de formulario normal).
No se toca vite.config.js ni package.json.
Verificación
npm run build (o npm run dev en paralelo) para que @vite resuelva assets.
php artisan serve (o el entorno que uses) y abrir /panel/login.
Probar con un usuario real de la tabla user de Dolibarr (login + password que ya funciona hoy contra /api/custom/integrospos/login.php) y confirmar que redirige a /panel mostrando su nombre.
Probar credenciales inválidas → vuelve al login con error, sin filtrar detalle.
Confirmar que /api/custom/integrospos/login.php sigue respondiendo igual que antes (sin cambios) — smoke test rápido con curl del endpoint existente.
Auth::guard('web')->logout() desde el botón de logout → vuelve a pedir login al reentrar a /panel.
Nota para más adelante: ionCube
ionCube encoding se aplica sobre los .php ya compilados/finales, típicamente en el paso de deploy (no en desarrollo). Blade compila sus .blade.php a PHP cacheado en storage/framework/views en tiempo de ejecución — conviene excluir storage/ del encoding y asegurarse que el encoder corra sobre una versión de PHP compatible con el ionCube Loader del servidor de producción.
No requiere cambios de diseño ahora; se retoma cuando haya código listo para empaquetar.
