@extends('layouts.app')
@section('title', __('storefront.account.order'))

@php
    use App\Support\Loc;
    use App\Support\Money;
    use App\Support\Nav;

    $items = $order['items'] ?? [];
@endphp

@section('content')
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="crumbs">
            <li><a href="{{ Nav::url('account') }}">{{ __('storefront.account.title') }}</a></li>
            <li><a href="{{ Nav::url('account/orders') }}">{{ __('storefront.account.orders') }}</a></li>
            <li><span aria-current="page">{{ __('storefront.account.order') }}</span></li>
        </ol>
    </nav>

    <div class="page-head">
        <h1 class="page-head__title">{{ __('storefront.account.order') }}</h1>
        <p class="page-head__count" dir="ltr">
            {{ $order['orderNumber'] ?? $order['_id'] ?? '' }}
        </p>
    </div>

    <div class="account" style="padding-block-end:var(--space-8)">
        @include('partials.account-nav')

        <div>
            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">{{ __('storefront.checkout.summary') }}</h2>
                    <span class="status status--pending">{{ $order['status'] ?? '' }}</span>
                </div>

                @foreach ($items as $item)
                    @php
                        $p = is_array($item['productId'] ?? null) ? $item['productId'] : [];
                    @endphp
                    <div style="display:flex;gap:var(--space-4);padding-block:var(--space-4);border-block-end:1px solid var(--line-soft)">
                        @if (! empty($p['mainImage']))
                            <img src="{{ $p['mainImage'] }}" alt="" width="60" height="75" loading="lazy"
                                 style="inline-size:60px;block-size:75px;object-fit:cover;border-radius:var(--radius-sm);flex:none">
                        @endif
                        <div style="flex:1;min-inline-size:0">
                            <p style="font-family:var(--font-display);font-size:var(--step-subhead)">
                                {{ Loc::text($p['title'] ?? null) }}
                            </p>
                            <p class="muted" style="font-size:var(--step-micro)">× {{ $item['quantity'] ?? 1 }}</p>
                        </div>
                        <x-price :now="$item['totalPriceAfterDiscount'] ?? $item['totalPrice'] ?? 0"
                                 :symbol="$item['symbol'] ?? null"/>
                    </div>
                @endforeach

                <dl style="margin-block-start:var(--space-4)">
                    <div class="summary__row">
                        <dt>{{ __('storefront.cart.subtotal') }}</dt>
                        <dd>{{ Money::format($order['subTotal'] ?? 0, $order['symbol'] ?? null) }}</dd>
                    </div>
                    @if (($order['deliveryFees'] ?? 0) > 0)
                        <div class="summary__row">
                            <dt>{{ __('storefront.cart.delivery') }}</dt>
                            <dd>{{ Money::format($order['deliveryFees'], $order['symbol'] ?? null) }}</dd>
                        </div>
                    @endif
                    <div class="summary__row summary__row--total">
                        <dt>{{ __('storefront.cart.total') }}</dt>
                        <dd>{{ Money::format($order['total'] ?? 0, $order['symbol'] ?? null) }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
