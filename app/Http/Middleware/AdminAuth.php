<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the store control panel.
 *
 * If no password hash is configured the panel does not exist at all — a 404,
 * not a login form with a default password. That way an unconfigured
 * deployment cannot be walked into.
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('admin.password_hash'), 404);

        if (! $request->session()->get('admin.authenticated')) {
            $request->session()->put('admin.intended', $request->fullUrl());

            return redirect('/admin/login');
        }

        return $next($request);
    }
}
