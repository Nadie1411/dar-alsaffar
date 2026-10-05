<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel speaks the language its user chose — Arabic until they say otherwise.
 */
class PanelLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('panel.locale')
            ?? Auth::guard('staff')->user()?->locale
            ?? 'ar';

        $locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';

        app()->setLocale($locale);

        view()->share([
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'isRtl' => $locale === 'ar',
        ]);

        return $next($request);
    }
}
