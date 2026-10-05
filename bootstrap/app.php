<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\EnsureLocalBackend;
use App\Http\Middleware\PanelAuth;
use App\Http\Middleware\PanelLocale;
use App\Http\Middleware\PanelModuleAccess;
use App\Http\Middleware\RedirectLegacyAdmin;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/panel.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production sits behind nginx on the host; the container port is
        // bound to 127.0.0.1 only, so every proxy that can reach us is ours.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SetLocale::class,
        ]);

        $middleware->alias([
            'admin' => AdminAuth::class,
            'admin.legacy' => RedirectLegacyAdmin::class,
            'panel.enabled' => EnsureLocalBackend::class,
            'panel.auth' => PanelAuth::class,
            'panel.locale' => PanelLocale::class,
            'panel.module' => PanelModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A form that fails validation is sent back with what was typed in it —
        // kept in the session store. The gateway's keys are never to be among it.
        $exceptions->dontFlash(['api_key', 'webhook_secret']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
