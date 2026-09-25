@extends('layouts.app')

@section('title', $title)
@section('description', $lede ?? __('storefront.listing.allLede'))

@php use App\Support\Nav; @endphp

@section('content')

    <div class="container">
        @if (! empty($crumbs))
            <nav aria-label="breadcrumb">
                <ol class="crumbs">
                    <li><a href="{{ Nav::url() }}">{{ __('storefront.nav.home') }}</a></li>
                    @foreach ($crumbs as $crumb)
                        <li>
                            @if (! empty($crumb['url']))
                                <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                            @else
                                <span aria-current="page">{{ $crumb['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif

        <div class="page-head">
            <h1 class="page-head__title">{{ $title }}</h1>
            @if ($lede)<p class="page-head__lede">{{ $lede }}</p>@endif
            <p class="page-head__count">
                {{ __('storefront.listing.count', ['count' => $products->total()]) }}
            </p>
        </div>

        <div class="listing">

            {{-- Desktop sidebar --}}
            <form class="filters" method="GET" id="filter-form">
                @if (! empty($filters['q']))
                    <input type="hidden" name="q" value="{{ $filters['q'] }}">
                @endif

                <h2 class="filter-group__title" style="font-size:var(--step-small);color:var(--text-primary)">
                    {{ __('storefront.listing.filters') }}
                </h2>

                @include('partials.filters', ['idPrefix' => 'd'])

                <div class="stack" style="--flow:var(--space-2);padding-block-start:var(--space-5)">
                    <button class="btn btn--block" type="submit">{{ __('storefront.actions.apply') }}</button>
                    <a class="btn btn--ghost btn--block" href="{{ url()->current() }}">
                        {{ __('storefront.actions.clearAll') }}
                    </a>
                </div>
            </form>

            <div>
                <div class="listing__toolbar">
                    <p class="listing__count">
                        @if ($products->total())
                            {{ __('storefront.listing.showing', [
                                'from'  => $products->firstItem(),
                                'to'    => $products->lastItem(),
                                'total' => $products->total(),
                            ]) }}
                        @endif
                    </p>

                    <form method="GET" style="display:flex;align-items:center;gap:var(--space-2)">
                        @foreach (request()->except(['sort', 'page']) as $key => $value)
                            @foreach ((array) $value as $v)
                                <input type="hidden" name="{{ $key }}{{ is_array($value) ? '[]' : '' }}" value="{{ $v }}">
                            @endforeach
                        @endforeach

                        <label class="visually-hidden" for="sort">{{ __('storefront.listing.sortBy') }}</label>
                        <select class="select" id="sort" name="sort" data-autosubmit style="min-block-size:40px">
                            @foreach ([
                                'recommended' => __('storefront.listing.sortRecommended'),
                                'newest'      => __('storefront.listing.sortNewest'),
                                'price-asc'   => __('storefront.listing.sortPriceAsc'),
                                'price-desc'  => __('storefront.listing.sortPriceDesc'),
                                'discount'    => __('storefront.listing.sortDiscount'),
                                'name'        => __('storefront.listing.sortName'),
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['sort'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                <div class="mobile-tools" style="margin-block-end:var(--space-5)">
                    <button class="btn btn--ghost" type="button" data-open="filter-sheet">
                        <x-icon name="filter" size="16"/> {{ __('storefront.actions.filter') }}
                    </button>
                    <a class="btn btn--ghost" href="#sort" onclick="document.getElementById('sort').focus();return false;">
                        <x-icon name="sort" size="16"/> {{ __('storefront.actions.sort') }}
                    </a>
                </div>

                @if ($products->total())
                    <x-product-grid :products="$products->items()"/>

                    @if ($products->hasPages())
                        <nav class="center" style="margin-block-start:var(--space-7)" aria-label="pagination">
                            {{ $products->onEachSide(1)->links() }}
                        </nav>
                    @endif
                @else
                    <x-empty-state
                        icon="search"
                        :title="__('storefront.listing.empty')"
                        :text="__('storefront.listing.emptyText')"
                        :href="Nav::url('products')"
                        :label="__('storefront.listing.allProducts')"/>
                @endif
            </div>
        </div>
    </div>

    {{-- Mobile bottom sheet --}}
    <div class="sheet" id="filter-sheet" data-panel="filter-sheet" role="dialog" aria-modal="true"
         aria-label="{{ __('storefront.listing.filters') }}" hidden>
        <span class="sheet__grip" aria-hidden="true"></span>

        <form method="GET" class="sheet__body" id="sheet-form">
            @if (! empty($filters['q']))
                <input type="hidden" name="q" value="{{ $filters['q'] }}">
            @endif
            <input type="hidden" name="sort" value="{{ $filters['sort'] ?? 'recommended' }}">
            @include('partials.filters', ['idPrefix' => 'm'])
        </form>

        <div class="sheet__foot">
            <a class="btn btn--ghost" href="{{ url()->current() }}">{{ __('storefront.actions.clearAll') }}</a>
            <button class="btn" type="submit" form="sheet-form">{{ __('storefront.actions.show') }}</button>
        </div>
    </div>

@endsection
