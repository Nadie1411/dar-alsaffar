@extends('layouts.app')
@section('title', __('storefront.account.orders'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="container">
    <div class="page-head"><h1 class="page-head__title">{{ __('storefront.account.orders') }}</h1></div>

    <div class="account" style="padding-block-end:var(--space-8)">
        @include('partials.account-nav')

        <div class="panel">
            @forelse ($orders as $order)
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
@endsection
