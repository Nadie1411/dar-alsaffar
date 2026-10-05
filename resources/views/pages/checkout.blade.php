@extends('layouts.app')

@section('title', __('storefront.checkout.title'))

@php
    use App\Support\Shopper;
    use App\Support\Nav;

    $old = fn (string $key, $fallback = '') => old($key, $fallback);
@endphp

@section('content')
    <div class="container">
        <div class="page-head">
            <h1 class="page-head__title">{{ __('storefront.checkout.title') }}</h1>
        </div>

        {{-- Everything is on one page, so these jump to the section rather
             than pretending to be a multi-page wizard. --}}
        <nav class="steps" aria-label="{{ __('storefront.checkout.title') }}">
            <ol class="steps__list">
                @foreach ([
                    'customer' => 'step-customer',
                    'address'  => 'step-address',
                    'payment'  => 'step-payment',
                ] as $key => $anchor)
                    <li class="step">
                        <a class="step__link" href="#{{ $anchor }}">
                            <span class="step__num">{{ $loop->iteration }}</span>
                            <span class="step__label">{{ __('storefront.checkout.'.$key) }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </nav>

        @if ($errors->has('checkout'))
            <p class="alert alert--error" role="alert">
                <x-icon name="info" size="16" class="alert__icon"/>
                {{ $errors->first('checkout') }}
            </p>
        @endif

        @unless (Shopper::check())
            <p class="alert alert--notice">
                <x-icon name="user" size="16" class="alert__icon"/>
                {!! __('storefront.checkout.guestNote', [
                    'link' => '<a class="link-underline" href="'.Nav::url('login').'">'
                        .e(__('storefront.checkout.guestLink')).'</a>',
                ]) !!}
            </p>
        @endunless

        <form method="POST" action="{{ Nav::url('checkout') }}" class="cart-layout"
              style="padding-block-end:var(--space-8)" novalidate>
            @csrf

            <div>
                {{-- ------------------------------------------ customer --}}
                <fieldset class="fieldset" id="step-customer">
                    <legend class="fieldset__legend">
                        <span class="fieldset__legend-inner">
                            <span class="step__num">1</span>{{ __('storefront.checkout.customer') }}
                        </span>
                    </legend>

                    <div class="grid-2">
                        <label class="field span-2">
                            <span class="field__label">
                                {{ __('storefront.checkout.fullName') }}<span class="field__required">*</span>
                            </span>
                            <input class="input" type="text" name="fullName" required autocomplete="name"
                                   value="{{ $old('fullName', $customer['fullName'] ?? '') }}"
                                   @error('fullName') aria-invalid="true" aria-describedby="err-fullName" @enderror>
                            @error('fullName')<span class="field__error" id="err-fullName">{{ $message }}</span>@enderror
                        </label>

                        <div class="field">
                            <span class="field__label">
                                {{ __('storefront.checkout.phone') }}<span class="field__required">*</span>
                            </span>
                            {{-- The country code is fixed: this store ships within Kuwait. --}}
                            <div class="phone-field">
                                <span class="phone-field__prefix" aria-hidden="true">+{{ $dial }}</span>
                                <input class="input" type="tel" name="phone" required dir="ltr"
                                       inputmode="numeric" autocomplete="tel-national"
                                       maxlength="{{ $phoneLen }}" pattern="[1-9][0-9]{{ '{'.($phoneLen - 1).'}' }}"
                                       value="{{ $old('phone') }}"
                                       aria-describedby="hint-phone @error('phone') err-phone @enderror"
                                       @error('phone') aria-invalid="true" @enderror>
                            </div>
                            <span class="field__hint" id="hint-phone">{{ __('storefront.checkout.phoneHint') }}</span>
                            @error('phone')<span class="field__error" id="err-phone">{{ $message }}</span>@enderror
                        </div>

                        <label class="field">
                            <span class="field__label">
                                {{ __('storefront.checkout.email') }}
                            </span>
                            <input class="input" type="email" name="email" autocomplete="email"
                                   inputmode="email" dir="ltr"
                                   value="{{ $old('email', $customer['email'] ?? '') }}"
                                   aria-describedby="hint-email @error('email') err-email @enderror"
                                   @error('email') aria-invalid="true" @enderror>
                            <span class="field__hint" id="hint-email">{{ __('storefront.checkout.emailHint') }}</span>
                            @error('email')<span class="field__error" id="err-email">{{ $message }}</span>@enderror
                        </label>
                    </div>
                </fieldset>

                {{-- ------------------------------------------- address --}}
                <fieldset class="fieldset" id="step-address">
                    <legend class="fieldset__legend">
                        <span class="fieldset__legend-inner">
                            <span class="step__num">2</span>{{ __('storefront.checkout.address') }}
                        </span>
                    </legend>

                    <div class="grid-2">
                        <label class="field">
                            <span class="field__label">
                                {{ __('storefront.checkout.city') }}<span class="field__required">*</span>
                            </span>
                            <select class="select" name="city" id="city" required
                                    @error('city') aria-invalid="true" @enderror>
                                <option value="">{{ __('storefront.checkout.selectCity') }}</option>
                                @foreach ($cities as $city)
                                    <option value="{{ $city['id'] }}" @selected($old('city') === $city['id'])>
                                        {{ $city['name'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('city')<span class="field__error">{{ $message }}</span>@enderror
                        </label>

                        <label class="field">
                            <span class="field__label">
                                {{ __('storefront.checkout.area') }}<span class="field__required">*</span>
                            </span>
                            <select class="select" name="area" id="area" required
                                    @error('area') aria-invalid="true" @enderror>
                                <option value="">{{ __('storefront.checkout.selectArea') }}</option>
                            </select>
                            @error('area')<span class="field__error">{{ $message }}</span>@enderror
                        </label>

                        <label class="field">
                            <span class="field__label">{{ __('storefront.checkout.block') }}</span>
                            <input class="input" type="text" name="block" value="{{ $old('block') }}">
                        </label>

                        <label class="field">
                            <span class="field__label">{{ __('storefront.checkout.street') }}</span>
                            <input class="input" type="text" name="street" value="{{ $old('street') }}"
                                   autocomplete="address-line1">
                        </label>

                        <label class="field">
                            <span class="field__label">
                                {{ __('storefront.checkout.avenue') }}
                                <span class="field__optional">({{ __('storefront.checkout.optional') }})</span>
                            </span>
                            <input class="input" type="text" name="avenue" value="{{ $old('avenue') }}">
                        </label>

                        <label class="field">
                            <span class="field__label">{{ __('storefront.checkout.building') }}</span>
                            <input class="input" type="text" name="building" value="{{ $old('building') }}">
                        </label>

                        <label class="field">
                            <span class="field__label">
                                {{ __('storefront.checkout.floor') }}
                                <span class="field__optional">({{ __('storefront.checkout.optional') }})</span>
                            </span>
                            <input class="input" type="text" name="floor" value="{{ $old('floor') }}">
                        </label>

                        <label class="field">
                            <span class="field__label">{{ __('storefront.checkout.apartment') }}</span>
                            <input class="input" type="text" name="apartment" value="{{ $old('apartment') }}">
                        </label>

                        <label class="field span-2">
                            <span class="field__label">{{ __('storefront.checkout.notes') }}</span>
                            <textarea class="textarea" name="notes" rows="3"
                                      aria-describedby="hint-notes">{{ $old('notes') }}</textarea>
                            <span class="field__hint" id="hint-notes">{{ __('storefront.checkout.notesHint') }}</span>
                        </label>
                    </div>
                </fieldset>

                @include('partials.gift-wrap', [
                    'addons'   => $addons,
                    'required' => $addonsRequired,
                    'symbol'   => $quote->symbol(),
                ])

                {{-- ------------------------------------------- payment --}}
                <fieldset class="fieldset" id="step-payment">
                    <legend class="fieldset__legend">
                        <span class="fieldset__legend-inner">
                            <span class="step__num">3</span>{{ __('storefront.checkout.payment') }}
                        </span>
                    </legend>

                    <div class="stack" style="--flow:var(--space-4)">
                        @if ($methods === [] && ! $codEnabled)
                            <p class="alert alert--error" role="alert">
                                <x-icon name="info" size="16" class="alert__icon"/>
                                {{ __('storefront.checkout.noPayment') }}
                            </p>
                        @endif

                        <div class="payment-primary">
                            @if ($methods !== [])
                                <label class="pay-card">
                                    <input type="radio" name="payment" value="online" data-payment
                                           @checked($old('payment', 'online') === 'online')>
                                    <span class="pay-card__icon"><x-icon name="card" size="22"/></span>
                                    <span class="pay-card__text">
                                        <span class="pay-card__title">{{ __('storefront.checkout.online') }}</span>
                                    </span>
                                    <span class="pay-card__tick"><x-icon name="check" size="14"/></span>
                                </label>
                            @endif

                            @if ($codEnabled)
                                <label class="pay-card">
                                    <input type="radio" name="payment" value="cod" data-payment
                                           @checked($old('payment', $methods === [] ? 'cod' : '') === 'cod')>
                                    <span class="pay-card__icon"><x-icon name="cash" size="22"/></span>
                                    <span class="pay-card__text">
                                        <span class="pay-card__title">{{ __('storefront.checkout.cod') }}</span>
                                        <span class="pay-card__note">
                                            {{ __('storefront.checkout.codNote') }}@if ($quote->codFee() > 0) · + {{ $quote->money($quote->codFee()) }}@endif
                                        </span>
                                    </span>
                                    <span class="pay-card__tick"><x-icon name="check" size="14"/></span>
                                </label>
                            @endif
                        </div>

                        @if (count($methods) === 1)
                            {{-- One way to pay is not a choice to put to the shopper. --}}
                            <input type="hidden" name="paymentMethod" value="{{ $methods[0]['id'] }}">
                        @elseif ($methods !== [])
                            <div id="payment-methods" class="payment-methods">
                                <p class="payment-methods__label">{{ __('storefront.checkout.paymentMethodLabel') }}</p>
                                <div class="payment-methods__grid">
                                    @foreach ($methods as $method)
                                        <label class="pay-method">
                                            <input type="radio" name="paymentMethod" value="{{ $method['id'] }}"
                                                   @checked($old('paymentMethod', $methods[0]['id'] ?? '') === $method['id'])>
                                            <span class="pay-method__logo"><x-pay-logo :type="$method['type']" :height="24"/></span>
                                            <span class="pay-method__label">{{ $method['label'] }}</span>
                                            <span class="pay-method__tick"><x-icon name="check" size="12"/></span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('paymentMethod')<span class="field__error">{{ $message }}</span>@enderror
                            </div>
                        @endif

                        @if ($methods !== [])
                            <p class="pay-trust" id="pay-trust">
                                <x-icon name="lock" size="16"/>
                                <span>{{ __('storefront.checkout.payTrust') }}</span>
                            </p>
                        @endif
                    </div>
                    @error('payment')<span class="field__error">{{ $message }}</span>@enderror
                </fieldset>
            </div>

            {{-- -------------------------------------------- summary --}}
            <aside>
                <div style="margin-block-end:var(--space-4)">
                    <x-promo-meter :quote="$quote"/>
                </div>

                <div class="summary">
                    <h2 class="panel__title" style="margin-block-end:var(--space-4)">
                        {{ __('storefront.checkout.summary') }}
                    </h2>

                    @foreach ($quote->lines() as $line)
                        @php $product = $line['product']; @endphp
                        <div style="display:flex;gap:var(--space-3);padding-block:var(--space-3);border-block-end:1px solid var(--line-soft)">
                            @if ($product?->image())
                                <img src="{{ $product->image() }}" alt="" width="48" height="60" loading="lazy"
                                     style="inline-size:48px;block-size:60px;object-fit:cover;border-radius:var(--radius-sm);flex:none">
                            @endif
                            <span style="flex:1;min-inline-size:0">
                                <span style="display:block;font-size:var(--step-small)">{{ $product?->name() }}</span>
                                <span class="muted" style="font-size:var(--step-micro)">× {{ $line['quantity'] }}</span>
                            </span>
                            <x-price :now="$line['total']" :symbol="$line['symbol']"/>
                        </div>
                    @endforeach

                    <dl style="margin-block-start:var(--space-4)">
                        <div class="summary__row">
                            <dt>{{ __('storefront.cart.subtotal') }}</dt>
                            <dd>{{ $quote->money($quote->subTotal()) }}</dd>
                        </div>
                        @if ($quote->discount() > 0)
                            <div class="summary__row summary__row--discount">
                                <dt>{{ __('storefront.cart.discount') }}</dt>
                                <dd>−{{ $quote->money($quote->discount()) }}</dd>
                            </div>
                        @endif
                        <div class="summary__row">
                            <dt>{{ __('storefront.cart.delivery') }}</dt>
                            <dd>
                                @if ($quote->deliveryResolved())
                                    {{ $quote->money($quote->deliveryFees()) }}
                                @else
                                    <span class="muted">{{ __('storefront.cart.deliveryAtCheckout') }}</span>
                                @endif
                            </dd>
                        </div>
                        @if ($quote->serviceAddonsTotal() > 0)
                            <div class="summary__row">
                                <dt>{{ __('storefront.addon.total') }}</dt>
                                <dd>{{ $quote->money($quote->serviceAddonsTotal()) }}</dd>
                            </div>
                        @endif
                        <div class="summary__row summary__row--total">
                            <dt>{{ __('storefront.cart.total') }}</dt>
                            <dd>{{ $quote->money($quote->total()) }}</dd>
                        </div>
                    </dl>

                    <div class="sticky-cta">
                        <button class="btn btn--lg btn--block" type="submit">
                            {{ __('storefront.checkout.placeOrder') }}
                        </button>
                    </div>

                    <p class="field__hint center" style="margin-block-start:var(--space-3)">
                        <x-icon name="shield" size="14" style="display:inline-block;vertical-align:-2px"/>
                        {{ __('storefront.values.secure') }}
                    </p>
                </div>
            </aside>
        </form>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    // Areas depend on the chosen governorate; both lists come from the API.
    const city = document.getElementById('city');
    const area = document.getElementById('area');
    const preselected = @json(old('area'));
    if (!city || !area) return;

    async function loadAreas() {
        area.innerHTML = '<option value="">{{ __('storefront.checkout.selectArea') }}</option>';
        if (!city.value) return;

        try {
            const res = await fetch(@json(Nav::url('checkout/areas')) + '/' + encodeURIComponent(city.value), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
            });
            for (const a of await res.json()) {
                const opt = new Option(a.name, a.id, false, a.id === preselected);
                area.add(opt);
            }
        } catch {
            area.innerHTML = '<option value="">{{ __('storefront.errors.generic') }}</option>';
        }
    }

    city.addEventListener('change', loadAreas);
    if (city.value) loadAreas();

    // Card method choices only matter when paying online.
    const methods = document.getElementById('payment-methods');
    const sync = () => {
        const online = document.querySelector('[data-payment][value="online"]')?.checked;
        const trust = document.getElementById('pay-trust');
        if (trust) trust.hidden = !online;
        if (!methods) return;
        methods.hidden = !online;
        methods.querySelectorAll('input').forEach((i) => { i.disabled = !online; });
    };
    document.querySelectorAll('[data-payment]').forEach((r) => r.addEventListener('change', sync));
    sync();
})();
</script>
@endpush
