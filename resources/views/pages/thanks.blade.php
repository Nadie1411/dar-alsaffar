@extends('layouts.app')
@section('title', __('storefront.checkout.thanks'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container container--narrow section center">
        <div class="success-mark" data-celebrate aria-hidden="true">
            <span class="success-mark__burst">
                @for ($i = 0; $i < 10; $i++)
                    <span class="success-mark__spark" style="--angle:{{ $i * 36 }}deg;--spark-color:var(--{{ $i % 2 === 0 ? 'gold-500' : 'emerald-500' }})"></span>
                @endfor
            </span>
            <svg class="success-mark__ring" viewBox="0 0 96 96" width="96" height="96" fill="none">
                <circle class="success-mark__circle" cx="48" cy="48" r="44" stroke="currentColor" stroke-width="2.5"/>
                <path class="success-mark__check" d="M28 50 L42 64 L70 34" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <h1 style="font-size:var(--step-display);margin-block:var(--space-4) var(--space-3)">
            {{ __('storefront.checkout.thanks') }}
        </h1>
        <p class="muted" style="max-inline-size:44ch;margin-inline:auto">{{ __('storefront.checkout.thanksText') }}</p>

        @if ($orderId)
            <p style="margin-block-start:var(--space-5)">
                <span class="badge badge--quiet">
                    {{ __('storefront.checkout.orderNumber') }}: <span dir="ltr">{{ $orderId }}</span>
                </span>
            </p>
        @endif

        <div style="display:flex;gap:var(--space-3);justify-content:center;flex-wrap:wrap;margin-block-start:var(--space-6)">
            <a class="btn" href="{{ Nav::url('products') }}">{{ __('storefront.actions.keepShopping') }}</a>
            <a class="btn btn--ghost" href="{{ Nav::url('account/orders') }}">{{ __('storefront.account.orders') }}</a>
        </div>
    </div>
@endsection
