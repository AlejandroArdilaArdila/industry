<?php

use App\Http\Middleware\TrimTrailingSlash;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Desactivado a pedido: la consulta (full scan de llx_facturedet, ~29s)
    // saturaba el MySQL compartido con api-local-python cada 15 min. Ya se
    // agrego un indice cubriente (idx_facturedet_resumen) que la baja a
    // ~2.5s, pero se pidio desactivar el job igual - ver docs/SERVICIO.md
    // de api-local-python para el contexto del diagnostico.
    // ->withSchedule(function (Schedule $schedule): void {
    //     $schedule->command('informes:refrescar-resumen-ventas-producto')
    //         ->everyFifteenMinutes()
    //         ->withoutOverlapping();
    // })
    // TRM diaria: la TRM oficial se publica en la mañana y el BCE a mediodía (hora de Bogotá).
    // Idempotente: la segunda corrida solo completa lo que la primera no alcanzó a traer.
    ->withSchedule(function (Schedule $schedule): void {
        foreach (['07:00', '12:30'] as $hora) {
            $schedule->command('trm:cargar')->dailyAt($hora)->timezone('America/Bogota')->withoutOverlapping();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(TrimTrailingSlash::class);

        if (env('PANEL_ENABLED', false)) {
            $middleware->redirectGuestsTo(fn () => route('login'));
            $middleware->redirectUsersTo(fn () => route('panel.dashboard'));
        } else {
            // withMiddleware() ya registró por defecto redirectGuestsTo(route('login')),
            // hay que anularlo explícitamente o cualquier ruta protegida por 'auth'
            // sin header Accept: application/json rompe con "Route [login] not defined".
            $middleware->redirectGuestsTo(null);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* siempre en JSON; en el panel, solo cuando la petición lo pide
        // (fetch con Accept: application/json), para que los errores de
        // validación lleguen como 422 y no como redirect.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // El POS sube cada respuesta de error a pos_logs_errores tal cual la
        // manda Laravel: con APP_DEBUG=true eso incluye el trace completo
        // (decenas de frames de vendor/Illuminate), asi que aca lo recortamos
        // a los frames de app/Http/Controllers y app/Services, que son los
        // unicos utiles para diagnosticar.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            $payload = [
                'message' => $e->getMessage() ?: class_basename($e),
            ];

            if (config('app.debug')) {
                $payload['exception'] = get_class($e);
                $payload['file'] = $e->getFile();
                $payload['line'] = $e->getLine();
                $payload['trace'] = collect($e->getTrace())
                    ->filter(fn (array $frame) => isset($frame['file'])
                        && (str_contains($frame['file'], '/app/Http/Controllers/')
                            || str_contains($frame['file'], '/app/Services/')))
                    ->map(fn (array $frame) => [
                        'file' => $frame['file'],
                        'line' => $frame['line'] ?? null,
                        'class' => $frame['class'] ?? null,
                        'type' => $frame['type'] ?? null,
                        'function' => $frame['function'] ?? null,
                    ])
                    ->values()
                    ->all();
            }

            return response()->json($payload, $status);
        });
    })->create();
