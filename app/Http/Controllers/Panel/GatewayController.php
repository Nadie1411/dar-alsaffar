<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Services\Store\ActivityLogger;
use App\Services\Store\Payments\GatewayConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Where an owner pastes the payment gateway's keys instead of editing the
 * server's .env. Owners only: whoever can change these decides which account
 * customers' money goes to.
 *
 * What is saved is encrypted, and nothing here — no page, no flash message, no
 * log entry — ever shows a secret back. The form takes new values or leaves the
 * saved ones alone; there is no way to read one out. Changing anything asks for
 * the owner's password again, so a session left open cannot quietly swap the keys.
 */
class GatewayController extends Controller
{
    public function __construct(
        protected GatewayConfig $gateway,
        protected ActivityLogger $log,
    ) {}

    public function edit(): View
    {
        $saved = $this->gateway->lastSaved();
        $endpoints = (array) config('myfatoorah.endpoints');

        return view('panel.payments.gateway', [
            'endpoints' => array_keys($endpoints),
            'environment' => array_search($saved?->api_url, $endpoints, true) ?: '',
            'serverUrl' => (string) config('myfatoorah.api_url'),
            'keySource' => $this->gateway->keySource(),
            'secretSource' => $this->gateway->secretSource(),
            'unreadable' => $this->gateway->hasUnreadableSecret(),
            'enabled' => $this->gateway->enabled(),
            'savedAt' => $saved?->updated_at,
            'savedBy' => $saved?->updater?->name,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // A key pasted from a document or an email arrives with a space or a line
        // break on the end; the framework trims every field before it gets here.
        $data = $request->validate([
            'environment' => ['nullable', Rule::in(array_keys((array) config('myfatoorah.endpoints')))],
            // Keys are one unbroken run of letters, digits and a few symbols: something with spaces, quotes or
            // "Bearer " in front is not a key, and is better refused than saved.
            'api_key' => ['nullable', 'string', 'max:4000', 'regex:/^[A-Za-z0-9_\-.=+\/]+$/'],
            'webhook_secret' => ['nullable', 'string', 'max:1000', 'regex:/^[\x21-\x7E]+$/'],
            'clear_api_key' => ['nullable', 'boolean'],
            'clear_webhook_secret' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'current_password' => ['required', 'string', 'current_password:staff'],
        ]);

        $staff = $request->user('staff');
        $credential = GatewayCredential::query()->firstOrNew(['provider' => GatewayCredential::MYFATOORAH]);
        $changes = [];

        foreach (['api_key' => 'clear_api_key', 'webhook_secret' => 'clear_webhook_secret'] as $field => $clear) {
            if (filled($data[$field] ?? null)) {
                $credential->{$field} = $data[$field];
                $changes[$field] = 'changed';
            } elseif ($request->boolean($clear) && $credential->exists && $credential->getRawOriginal($field) !== null) {
                $credential->{$field} = null;
                $changes[$field] = 'removed';
            }
        }

        $url = filled($data['environment'] ?? null) ? config('myfatoorah.endpoints.'.$data['environment']) : null;

        if ($url !== $credential->api_url) {
            $credential->api_url = $url;
            $changes['environment'] = $data['environment'] ?? 'server';
        }

        $enabled = $request->boolean('enabled');

        if ($credential->enabled !== $enabled) {
            $credential->enabled = $enabled;
            $changes['enabled'] = $enabled;
        }

        $credential->updated_by = $staff->id;
        $credential->save();

        // The list of methods checkout offers belongs to the account that was asked: ask again.
        foreach (['ar', 'en'] as $locale) {
            Cache::forget('myfatoorah:methods:'.$locale);
        }

        // Which fields changed — never what they were changed to.
        if ($changes !== []) {
            $this->log->record($staff, 'payment.credentials_updated', null, $changes, 'MyFatoorah');
        }

        return redirect()->route('panel.payments.index')->with('status', __('panel.gateway.saved'));
    }
}
