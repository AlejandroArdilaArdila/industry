<?php

namespace App\Providers;

use App\Auth\ApiKeyGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Guard 'apikey': autentica por el header DOLAPIKEY contra el
        // api_key de Dolibarr. Se usa con el middleware auth:apikey.
        Auth::extend('apikey', function ($app, $name, array $config) {
            return new ApiKeyGuard(
                Auth::createUserProvider($config['provider']),
                $app['request'],
            );
        });
    }
}
