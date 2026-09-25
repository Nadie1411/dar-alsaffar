@extends('layouts.app')

@section('title', __('storefront.listing.categories'))
@section('description', __('storefront.home.collectionsLede'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="crumbs">
                <li><a href="{{ Nav::url() }}">{{ __('storefront.nav.home') }}</a></li>
                <li><span aria-current="page">{{ __('storefront.listing.categories') }}</span></li>
            </ol>
        </nav>

        <div class="page-head">
            <h1 class="page-head__title">{{ __('storefront.home.collectionsTitle') }}</h1>
            <p class="page-head__lede">{{ __('storefront.home.collectionsLede') }}</p>
        </div>

        @if (count($collections))
            <div class="product-grid product-grid--3" style="gap:var(--space-4);padding-block-end:var(--space-8)">
                @foreach ($collections as $collection)
                    <a @class(['collection-card', 'collection-card--bare' => ! $collection['image'],
                               'collection-card--product-shot' => ! empty($collection['imageFromProduct'])]) href="{{ Nav::url('categories/'.$collection['slug']) }}"
                       data-reveal data-reveal-delay="{{ $loop->index * 60 }}">
                        @if ($collection['image'])
                            <img src="{{ $collection['image'] }}" alt="" width="800" height="800"
                                 loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}" decoding="async">
                        @endif
                        <span>
                            <span class="collection-card__count">
                                {{ __('storefront.listing.count', ['count' => $collection['count']]) }}
                            </span>
                            <span class="collection-card__name">{{ $collection['name'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @else
            <x-empty-state icon="grid" :title="__('storefront.listing.empty')"/>
        @endif
    </div>
@endsection
