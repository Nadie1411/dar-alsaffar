@extends('layouts.app')
@section('title', __('storefront.errors.404'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="container container--narrow section center">
    <p class="section-heading__eyebrow" style="justify-content:center">404</p>
    <h1 style="font-size:var(--step-display);margin-block-end:var(--space-3)">{{ __('storefront.errors.404') }}</h1>
    <p class="muted" style="max-inline-size:44ch;margin-inline:auto">{{ __('storefront.errors.404Text') }}</p>

    <div style="display:flex;gap:var(--space-3);justify-content:center;flex-wrap:wrap;margin-block-start:var(--space-6)">
        <a class="btn" href="{{ Nav::url() }}">{{ __('storefront.errors.backHome') }}</a>
        <a class="btn btn--ghost" href="{{ Nav::url('products') }}">{{ __('storefront.listing.allProducts') }}</a>
    </div>
</div>
@endsection
