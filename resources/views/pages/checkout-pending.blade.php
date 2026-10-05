@extends('layouts.app')
@section('title', __('storefront.checkout.pendingTitle'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container container--narrow section center">
        <x-icon name="info" size="44" style="margin-inline:auto;color:var(--gold-500)"/>
        <h1 style="font-size:var(--step-title);margin-block:var(--space-4) var(--space-3)">
            {{ __('storefront.checkout.pendingTitle') }}
        </h1>
        <p class="muted" style="max-inline-size:46ch;margin-inline:auto">{{ __('storefront.checkout.pendingText') }}</p>

        @if ($orderId)
            <p style="margin-block-start:var(--space-5)">
                <span class="badge badge--quiet">
                    {{ __('storefront.checkout.orderNumber') }}: <span dir="ltr">{{ $orderId }}</span>
                </span>
            </p>
        @endif

        <div style="display:flex;gap:var(--space-3);justify-content:center;flex-wrap:wrap;margin-block-start:var(--space-6)">
            <a class="btn" href="{{ Nav::url('contact-us') }}">{{ __('storefront.nav.contact') }}</a>
            <a class="btn btn--ghost" href="{{ Nav::url() }}">{{ __('storefront.errors.backHome') }}</a>
        </div>
    </div>
@endsection
