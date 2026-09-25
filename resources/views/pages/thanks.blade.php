@extends('layouts.app')
@section('title', __('storefront.checkout.thanks'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container container--narrow section center">
        <x-icon name="check" size="44" style="margin-inline:auto;color:var(--emerald-700)"/>
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
