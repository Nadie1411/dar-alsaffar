<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LanguageController extends Controller
{
    /** Switches the panel between Arabic and English, and remembers the choice on the account. */
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $request->session()->put('panel.locale', $locale);

        Auth::guard('staff')->user()?->forceFill(['locale' => $locale])->save();

        $previous = url()->previous();

        return redirect()->to(str_starts_with($previous, url('/panel')) ? $previous : route('panel.dashboard'));
    }
}
