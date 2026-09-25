@extends('layouts.app')

@section('title', $term !== '' ? __('storefront.search.resultsFor', ['term' => $term]) : __('storefront.actions.search'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container">
        <div class="page-head">
            <h1 class="page-head__title">
                {{ $term !== '' ? __('storefront.search.resultsFor', ['term' => $term]) : __('storefront.search.title') }}
            </h1>
            @if ($term !== '')
                <p class="page-head__count">{{ __('storefront.listing.count', ['count' => count($products)]) }}</p>
            @endif
        </div>

        <form role="search" method="GET" action="{{ Nav::url('search') }}"
              style="display:flex;gap:var(--space-2);max-inline-size:min(100%,34rem);margin-block-end:var(--space-7)">
            <label class="visually-hidden" for="q">{{ __('storefront.search.title') }}</label>
            <input class="input" id="q" type="search" name="q" value="{{ $term }}"
                   placeholder="{{ __('storefront.search.placeholder') }}" autofocus>
            <button class="btn" type="submit">{{ __('storefront.actions.search') }}</button>
        </form>

        @if ($term === '')
            <p class="section-heading__eyebrow">{{ __('storefront.search.categories') }}</p>
            <div class="search-chips" style="margin-block-end:var(--space-8)">
                @foreach ($categories as $category)
                    <a class="chip" href="{{ Nav::url('categories/'.$category['slug']) }}">{{ $category['name'] }}</a>
                @endforeach
            </div>
        @elseif (count($products))
            <div style="padding-block-end:var(--space-8)">
                <x-product-grid :products="$products"/>
            </div>
        @else
            <x-empty-state icon="search"
                           :title="__('storefront.search.noResults')"
                           :text="__('storefront.search.noResultsText')"
                           :href="Nav::url('products')"
                           :label="__('storefront.listing.allProducts')"/>
        @endif
    </div>
@endsection
