@extends('layouts.app')
@section('title', __('storefront.checkout.failed'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container container--narrow section center">
        <x-icon name="info" size="44" style="margin-inline:auto;color:var(--danger)"/>
        <h1 style="font-size:var(--step-title);margin-block:var(--space-4) var(--space-3)">
            {{ __('storefront.checkout.failed') }}
        </h1>
        <p class="muted" style="max-inline-size:46ch;margin-inline:auto">{{ __('storefront.checkout.failedText') }}</p>

        <div style="display:flex;gap:var(--space-3);justify-content:center;flex-wrap:wrap;margin-block-start:var(--space-6)">
            <a class="btn" href="{{ Nav::url('cart') }}">{{ __('storefront.cart.title') }}</a>
            <a class="btn btn--ghost" href="{{ Nav::url('contact-us') }}">{{ __('storefront.nav.contact') }}</a>
        </div>
    </div>
@endsection
