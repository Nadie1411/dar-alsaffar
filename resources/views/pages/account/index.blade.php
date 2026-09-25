@extends('layouts.app')
@section('title', __('storefront.account.title'))

@php use App\Support\Nav; use App\Support\Money; use App\Support\Loc; @endphp

@section('content')
<div class="container">
    <div class="page-head">
        <h1 class="page-head__title">
            {{ __('storefront.account.greeting', ['name' => $customer['fullName'] ?? $customer['name'] ?? '']) }}
        </h1>
    </div>

    @if (session('status'))
        <p class="alert alert--success" role="status">
            <x-icon name="check" size="16" class="alert__icon"/> {{ session('status') }}
        </p>
    @endif

    <div class="account" style="padding-block-end:var(--space-8)">
        @include('partials.account-nav')

        <div>
            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">{{ __('storefront.account.overview') }}</h2>
                </div>

                <div class="value-row" style="text-align:start">
                    <div>
                        <p class="order-row__label">{{ __('storefront.account.orders') }}</p>
                        <p style="font-size:var(--step-heading)">{{ $orderCount }}</p>
                    </div>
                    <div>
                        <p class="order-row__label">{{ __('storefront.wishlist.title') }}</p>
                        <p style="font-size:var(--step-heading)">{{ $wishCount }}</p>
                    </div>
                    <div>
                        <p class="order-row__label">{{ __('storefront.auth.email') }}</p>
                        <p dir="ltr" style="font-size:var(--step-small)">{{ $customer['email'] ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="order-row__label">{{ __('storefront.auth.phone') }}</p>
                        <p dir="ltr" style="font-size:var(--step-small)">{{ $customer['phoneNumber'] ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">{{ __('storefront.account.orders') }}</h2>
                    <a class="link-underline" href="{{ Nav::url('account/orders') }}">
                        {{ __('storefront.nav.all') }}
                        <x-icon name="arrow" size="14" class="icon-arrow"/>
                    </a>
                </div>

                @forelse ($recentOrders as $order)
                    @include('partials.order-row', ['order' => $order])
                @empty
                    <x-empty-state icon="package"
                                   :title="__('storefront.account.noOrders')"
                                   :text="__('storefront.account.noOrdersText')"
                                   :href="Nav::url('products')"/>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
