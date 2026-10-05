<?php

namespace App\Http\Middleware;

use App\Enums\PanelModule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `panel.module:orders` — the signed-in staff member's role must include that area.
 */
class PanelModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $allowed = Auth::guard('staff')->user()?->canAccess(PanelModule::from($module)) ?? false;

        abort_unless($allowed, 403);

        return $next($request);
    }
}
