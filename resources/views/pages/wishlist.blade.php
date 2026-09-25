@extends('layouts.app')
@section('title', __('storefront.wishlist.title'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container">
        <div class="page-head">
            <h1 class="page-head__title">{{ __('storefront.wishlist.title') }}</h1>
            @if (count($products))
                <p class="page-head__count">
                    {{ __('storefront.wishlist.count', ['count' => count($products)]) }}
                </p>
            @endif
        </div>

        @if (count($products))
            <div style="padding-block-end:var(--space-8)">
                <x-product-grid :products="$products"/>
            </div>
        @else
            <x-empty-state icon="heart"
                           :title="__('storefront.wishlist.empty')"
                           :text="__('storefront.wishlist.emptyText')"
                           :href="Nav::url('products')"
                           :label="__('storefront.actions.shopNow')"/>
        @endif
    </div>
@endsection
