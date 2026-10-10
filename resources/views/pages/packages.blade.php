@extends('layouts.app')
@section('title', __('storefront.nav.packages'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="crumbs">
                <li><a href="{{ Nav::url() }}">{{ __('storefront.nav.home') }}</a></li>
                <li><span aria-current="page">{{ __('storefront.nav.packages') }}</span></li>
            </ol>
        </nav>

        <div class="page-head">
            <h1 class="page-head__title">{{ __('storefront.nav.packages') }}</h1>
        </div>

        @if (count($packages))
            <div class="product-grid" style="padding-block-end:var(--space-8)">
                @foreach ($packages as $package)
                    <article class="product-card">
                        <a class="product-card__media" href="{{ Nav::url('products/'.$package->slug()) }}">
                            @if ($package->image())
                                <img class="product-card__img product-card__img--main" src="{{ $package->image() }}"
                                     alt="" width="600" height="750" loading="lazy" decoding="async">
                            @endif
                        </a>
                        <div class="product-card__body">
                            <p class="product-card__kicker">{{ __('storefront.nav.packages') }}</p>
                            <h2 class="product-card__name">
                                <a href="{{ Nav::url('products/'.$package->slug()) }}">{{ $package->name() }}</a>
                            </h2>
                            <x-price :product="$package"/>
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <x-empty-state icon="package"
                           :title="__('storefront.bundle.empty')"
                           :text="__('storefront.bundle.emptyText')"
                           :href="Nav::url('products')"
                           :label="__('storefront.listing.allProducts')"/>
        @endif
    </div>
@endsection
