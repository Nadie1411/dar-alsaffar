@extends('layouts.app')
@section('title', __('storefront.checkout.applePay.title'))

@php
    use App\Support\Nav;

    $text = [
        'loading' => __('storefront.checkout.applePay.loading'),
        'slow' => __('storefront.checkout.applePay.slow'),
        'checking' => __('storefront.checkout.applePay.checking'),
        'failed' => __('storefront.checkout.applePay.failed'),
        'notPaid' => __('storefront.checkout.applePay.notPaid'),
        'unavailable' => __('storefront.checkout.applePay.unavailable'),
    ];
@endphp

@section('content')
    <div class="container container--narrow section center">
        <h1 style="font-size:var(--step-title);margin-block-end:var(--space-3)">{{ __('storefront.checkout.applePay.title') }}</h1>
        <p class="muted" style="max-inline-size:46ch;margin-inline:auto;margin-block-end:var(--space-5)">{{ __('storefront.checkout.applePay.intro') }}</p>

        <div class="apple-pay">
            <dl class="apple-pay__summary">
                <div>
                    <dt>{{ __('storefront.checkout.orderNumber') }}</dt>
                    <dd dir="ltr">{{ $order->number }}</dd>
                </div>
                <div>
                    <dt>{{ __('storefront.checkout.applePay.total') }}</dt>
                    <dd>{{ $total }}</dd>
                </div>
            </dl>

            @if ($sessionId)
                <div id="apple-pay"
                     data-session="{{ $sessionId }}"
                     data-sdk="{{ $sdkUrl }}"
                     data-confirm="{{ $confirmUrl }}"
                     data-pending="{{ Nav::url('checkout/pending/'.$order->number) }}"
                     data-language="{{ app()->getLocale() === 'en' ? 'en' : 'ar' }}"
                     data-text="{{ json_encode($text, JSON_UNESCAPED_UNICODE) }}">
                    <div id="apple-pay-button" class="apple-pay__button"></div>
                </div>
                <p id="apple-pay-status" class="apple-pay__status" role="status" aria-live="polite"></p>
                <button id="apple-pay-retry" class="btn" type="button" onclick="location.reload()" hidden>{{ __('storefront.checkout.tryAgain') }}</button>
            @else
                <p class="alert alert--error" role="alert">
                    <x-icon name="info" size="16" class="alert__icon"/>
                    {{ __('storefront.checkout.applePay.noSession') }}
                </p>
            @endif

            <p class="pay-trust" style="justify-content:center">
                <x-icon name="lock" size="16"/>
                <span>{{ __('storefront.checkout.applePay.trust') }}</span>
            </p>

            <div class="apple-pay__actions">
                <a class="btn btn--ghost" href="{{ $otherWayUrl }}">{{ __('storefront.checkout.applePay.otherWay') }}</a>
            </div>
        </div>
    </div>
@endsection

@if ($sessionId)
    @push('scripts')
        <script src="{{ \App\Support\Asset::url('assets/js/apple-pay.js') }}" defer></script>
    @endpush
@endif
