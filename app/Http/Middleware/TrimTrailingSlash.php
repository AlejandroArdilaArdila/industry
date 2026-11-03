<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * El cliente (app movil / integraciones tipo Dolibarr) a veces pega '/' al
 * final de la URL de forma inconsistente (ej. .../update/check y
 * .../update/check/). Esto normaliza el path ANTES del ruteo (va primero en
 * el stack global) para que ambas formas resuelvan a la misma ruta, sin
 * necesidad de declarar cada endpoint dos veces.
 */
class TrimTrailingSlash
{
    public function handle(Request $request, Closure $next)
    {
        $path = $request->getPathInfo();

        if ($path === '/' || !str_ends_with($path, '/')) {
            return $next($request);
        }

        $server = $request->server->all();
        $server['REQUEST_URI'] = rtrim($request->getRequestUri(), '/');

        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server,
            $request->getContent()
        );

        return $next($request);
    }
}
