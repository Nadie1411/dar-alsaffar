<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The old single-password panel belongs to the Overzaki setup. Once the store
 * runs on its own database, anyone who still has /admin bookmarked lands in the
 * new panel instead.
 */
class RedirectLegacyAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('store.backend') === 'local') {
            return redirect()->route('panel.dashboard');
        }

        return $next($request);
    }
}
