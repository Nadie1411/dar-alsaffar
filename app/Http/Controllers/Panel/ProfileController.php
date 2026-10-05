<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PanelAuth;
use App\Services\Store\ActivityLogger;
use App\Services\Store\Push\VapidKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * A member of staff's own details: their name, the language the panel speaks to
 * them and their password. Open to everyone who can sign in.
 */
class ProfileController extends Controller
{
    public function __construct(protected ActivityLogger $log) {}

    public function edit(Request $request, VapidKeys $keys): View
    {
        return view('panel.profile', ['member' => $request->user('staff'), 'pushKey' => $keys->publicKey()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $member = $request->user('staff');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'current_password' => ['nullable', 'required_with:password', 'current_password:staff'],
            'password' => ['nullable', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $member->fill(['name' => trim($data['name']), 'locale' => $data['locale']]);

        $passwordChanged = filled($data['password'] ?? null);

        if ($passwordChanged) {
            $member->password = $data['password'];
        }

        $member->save();

        $request->session()->put('panel.locale', $member->locale);

        // This session stays signed in; every other one is signed out at its next request.
        $request->session()->put(PanelAuth::SESSION_KEY, PanelAuth::fingerprint($member));

        $this->log->record($member, 'profile.updated', $member, $passwordChanged ? ['password_changed' => true] : []);

        return redirect()->route('panel.profile.edit')->with('status', __('panel.profile.saved'));
    }
}
