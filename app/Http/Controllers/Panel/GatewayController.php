<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Services\Store\ActivityLogger;
use App\Services\Store\Payments\GatewayConfig;
use App\Services\Store\Payments\MyFatoorahClient;
use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\MyFatoorahUnavailable;
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
        protected MyFatoorahClient $client,
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
            'applePay' => [
                'onSite' => $this->gateway->applePayOnSite(),
                'scriptKnown' => $this->gateway->sdkUrl() !== null,
                'file' => $this->gateway->applePayDomainFile() ?? '',
                'fileUrl' => route('apple-pay.domain-file'),
                'host' => request()->getHost(),
                'registeredDomain' => $this->gateway->applePayDomain(),
                'registeredAt' => $this->gateway->applePayRegisteredAt(),
            ],
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
            'apple_pay_on_site' => ['nullable', 'boolean'],
            // Plain text Apple's file is made of: refused if it is anything else, since it is served to the public.
            'apple_pay_domain_file' => ['nullable', 'string', 'max:20000', 'regex:/^[\x09\x0A\x0D\x20-\x7E]+$/'],
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

        // A browser sends a text box's line breaks as CRLF; the file is kept with plain ones.
        $file = filled($data['apple_pay_domain_file'] ?? null) ? str_replace("\r\n", "\n", $data['apple_pay_domain_file']) : null;

        if ($file !== $credential->apple_pay_domain_file) {
            $credential->apple_pay_domain_file = $file;
            // What was registered was registered with the old file.
            $credential->apple_pay_domain = null;
            $credential->apple_pay_registered_at = null;
            $changes['apple_pay_domain_file'] = $file === null ? 'removed' : 'changed';
        }

        $onSite = $request->boolean('apple_pay_on_site');
        $wasOnSite = $this->gateway->applePayOnSite();
        $registered = $credential->apple_pay_domain_file !== null && $credential->apple_pay_registered_at !== null;

        // A button on this site that MyFatoorah has not been told about would only ever fail. That is
        // checked when it is being switched on, and again when the owner saved it on and has since
        // changed the file; a switch left on in the server's own setting is not held to it.
        if ($onSite && ! $registered && ($onSite !== $wasOnSite || $credential->apple_pay_on_site === true)) {
            return back()->withInput()->withErrors(['apple_pay_on_site' => __('panel.gateway.applePay.needRegistered')]);
        }

        if ($onSite !== $wasOnSite) {
            $credential->apple_pay_on_site = $onSite;
            $changes['apple_pay_on_site'] = $onSite;
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

    /**
     * Tell MyFatoorah that this site's domain may show Apple Pay. It looks for
     * the verification file on the domain, so the file has to be saved first.
     * The domain is the one the owner is on right now: the live shop's, when
     * they do this from there.
     */
    public function registerApplePayDomain(Request $request): RedirectResponse
    {
        if ($this->gateway->applePayDomainFile() === null) {
            return back()->with('warning', __('panel.gateway.applePay.needFile'));
        }

        if (! $this->client->isConfigured()) {
            return back()->with('warning', __('panel.payments.notConfigured'));
        }

        $host = strtolower($request->getHost());

        // A real domain name, nothing else: it is what MyFatoorah is told to trust.
        if (preg_match('/^(?=.{4,190}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) !== 1 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return back()->with('warning', __('panel.gateway.applePay.badHost', ['host' => $host]));
        }

        try {
            $this->client->registerApplePayDomain($host);
        } catch (MyFatoorahUnavailable) {
            return back()->with('warning', __('panel.payments.unreachable'));
        } catch (MyFatoorahException) {
            return back()->with('warning', __('panel.gateway.applePay.refused', ['host' => $host]));
        }

        $staff = $request->user('staff');

        GatewayCredential::query()->where('provider', GatewayCredential::MYFATOORAH)->update([
            'apple_pay_domain' => $host,
            'apple_pay_registered_at' => now(),
            'updated_by' => $staff->id,
        ]);
        $this->gateway->refresh();

        $this->log->record($staff, 'payment.apple_pay_domain_registered', null, ['domain' => $host], 'MyFatoorah');

        return back()->with('status', __('panel.gateway.applePay.registered', ['host' => $host]));
    }
}
