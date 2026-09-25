@extends('layouts.app')
@section('title', __('storefront.account.addresses'))

@php use App\Support\Loc; use App\Support\Nav; @endphp

@section('content')
<div class="container">
    <div class="page-head"><h1 class="page-head__title">{{ __('storefront.account.addresses') }}</h1></div>

    <div class="account" style="padding-block-end:var(--space-8)">
        @include('partials.account-nav')

        <div class="panel">
            @forelse ($addresses as $address)
                <div class="order-row" style="grid-template-columns:1fr">
                    <p style="font-size:var(--step-small)">
                        {{ collect([
                            Loc::text($address['city']['name'] ?? $address['city'] ?? null),
                            Loc::text($address['area']['name'] ?? $address['area'] ?? null),
                            $address['block'] ?? null,
                            $address['street'] ?? null,
                            $address['building'] ?? null,
                        ])->filter()->implode('، ') }}
                    </p>
                </div>
            @empty
                <x-empty-state icon="pin"
                               :title="__('storefront.account.noAddresses')"
                               :href="Nav::url('products')"
                               :label="__('storefront.actions.shopNow')"/>
            @endforelse
        </div>
    </div>
</div>
@endsection
