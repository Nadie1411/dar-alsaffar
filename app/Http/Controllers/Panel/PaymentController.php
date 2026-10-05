<?php

namespace App\Http\Controllers\Panel;

use App\Enums\PanelModule;
use App\Enums\PaymentState;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Rules\Dinars;
use App\Services\Settings;
use App\Services\Store\ActivityLogger;
use App\Services\Store\Payments\GatewayConfig;
use App\Services\Store\Payments\MyFatoorahClient;
use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\MyFatoorahUnavailable;
use App\Services\Store\Payments\PaymentService;
use App\Services\Store\Pricing\CommerceSettings;
use App\Support\Money;
use App\Support\PanelFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected MyFatoorahClient $client,
        protected Settings $settings,
        protected CommerceSettings $commerce,
        protected ActivityLogger $log,
        protected GatewayConfig $gatewayConfig,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $payments = Payment::query()
            ->with('order')
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['q'].'%';

                $query->where(fn (Builder $query) => $query
                    ->where('reference', 'like', $term)
                    ->orWhere('mf_invoice_id', 'like', $term)
                    ->orWhere('mf_payment_id', 'like', $term)
                    ->orWhereHas('order', fn (Builder $query) => $query->where('number', 'like', $term)));
            })
            ->when($filters['state'] !== '', fn (Builder $query) => $query->where('state', $filters['state']))
            ->when($filters['review'], fn (Builder $query) => $query->needsReview())
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('panel.payments.index', [
            'payments' => $payments,
            'filters' => $filters,
            'gateway' => $this->gateway(),
            'canManageKeys' => $request->user('staff')->canAccess(PanelModule::Gateway),
            'needReview' => Payment::query()->needsReview()->count(),
            'codEnabled' => $this->commerce->cashOnDeliveryEnabled(),
            'codFee' => PanelFormat::amount($this->commerce->codFeeFils()),
        ]);
    }

    /** Asks MyFatoorah about one payment now, rather than waiting for the next scheduled check. */
    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        if (! $this->client->isConfigured()) {
            return back()->with('warning', __('panel.payments.notConfigured'));
        }

        try {
            $outcome = $this->payments->recheck($payment);
        } catch (MyFatoorahUnavailable) {
            return back()->with('warning', __('panel.payments.unreachable'));
        } catch (MyFatoorahException) {
            return back()->with('warning', __('panel.payments.refused'));
        }

        $this->log->record($request->user('staff'), 'payment.rechecked', $payment, ['result' => $outcome->result], $this->label($payment));

        return back()->with(
            $outcome->isPaid() ? 'status' : 'warning',
            __('panel.payments.recheck.'.$outcome->result)
        );
    }

    /** Records that somebody has looked at a flagged payment and dealt with it. */
    public function review(Request $request, Payment $payment): RedirectResponse
    {
        if ($payment->anomaly === null) {
            return back();
        }

        $payment->update([
            'anomaly_reviewed_at' => now(),
            'anomaly_reviewed_by' => $request->user('staff')->id,
        ]);

        $this->log->record($request->user('staff'), 'payment.reviewed', $payment, ['anomaly' => $payment->anomaly], $this->label($payment));

        return back()->with('status', __('panel.payments.reviewed'));
    }

    /**
     * Calls MyFatoorah with the configured key and reports what it says. This
     * is how the shop finds out, without a real payment, that the key works and
     * which methods the account has enabled.
     */
    public function testConnection(Request $request): RedirectResponse
    {
        if (! $this->client->isConfigured()) {
            return back()->with('warning', __('panel.payments.notConfigured'));
        }

        try {
            $methods = collect($this->client->paymentMethods())->pluck('name')->filter()->all();
        } catch (MyFatoorahUnavailable) {
            return back()->with('warning', __('panel.payments.unreachable'));
        } catch (MyFatoorahException) {
            // A key can be perfectly good for taking payments yet not allowed to
            // list methods; checkout then offers one plain "pay online" choice.
            return back()->with('warning', __('panel.payments.cannotList'));
        }

        // A fresh answer replaces whatever checkout had remembered.
        foreach (['ar', 'en'] as $locale) {
            Cache::forget('myfatoorah:methods:'.$locale);
        }

        $this->log->record($request->user('staff'), 'payment.connection_tested', null, ['methods' => count($methods)], __('panel.modules.payments'));

        return back()->with('status', $methods === []
            ? __('panel.payments.connectedNoMethods')
            : __('panel.payments.connected', ['methods' => implode('، ', $methods)]));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['cod_fee' => ['nullable', new Dinars]]);

        $this->settings->save([
            'checkout.cod' => $request->boolean('cod_enabled'),
            'commerce.cod_fee_fils' => filled($data['cod_fee'] ?? null) ? (int) Money::parseFils($data['cod_fee']) : 0,
        ]);

        $this->log->record($request->user('staff'), 'payment.settings_updated', null, [
            'cod_enabled' => $request->boolean('cod_enabled'),
            'cod_fee_fils' => $this->commerce->codFeeFils(),
        ], __('panel.modules.payments'));

        return back()->with('status', __('panel.payments.settingsSaved'));
    }

    /** What a payment is called in the activity log: the order it was for. */
    protected function label(Payment $payment): string
    {
        return $payment->order?->number ?? $payment->reference;
    }

    /**
     * @return array{q:string,state:string,review:bool}
     */
    protected function filters(Request $request): array
    {
        $state = (string) $request->query('state', '');

        return [
            'q' => trim(str_replace(['%', '_', '\\'], ' ', (string) $request->query('q', ''))),
            'state' => PaymentState::tryFrom($state) === null ? '' : $state,
            'review' => $request->boolean('review'),
        ];
    }

    /**
     * What the gateway is set up as, for the panel to show — never the key itself.
     *
     * @return array<string,mixed>
     */
    protected function gateway(): array
    {
        $url = $this->gatewayConfig->apiUrl();

        return [
            'configured' => $this->client->isConfigured(),
            'hasKey' => $this->gatewayConfig->keySource() !== GatewayConfig::SOURCE_NONE,
            'keySource' => $this->gatewayConfig->keySource(),
            'switchedOn' => $this->gatewayConfig->enabled(),
            'host' => parse_url($url, PHP_URL_HOST) ?: $url,
            'sandbox' => str_contains($url, 'apitest'),
            'secretSet' => $this->gatewayConfig->secretSource() !== GatewayConfig::SOURCE_NONE,
            'secretSource' => $this->gatewayConfig->secretSource(),
            'unreadable' => $this->gatewayConfig->hasUnreadableSecret(),
            'webhookUrl' => $this->payments->callbackUrl('webhooks.myfatoorah'),
            'returnUrl' => $this->payments->callbackUrl('payment.return', ['locale' => 'ar-KW']),
        ];
    }
}
