<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
