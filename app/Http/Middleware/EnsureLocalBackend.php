<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel manages this application's own shop. While the store still
 * runs on Overzaki there is nothing here to manage, so the panel does not
 * exist: a 404, not a login page that leads nowhere.
 */
class EnsureLocalBackend
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('store.backend') === 'local', 404);

        return $next($request);
    }
}
