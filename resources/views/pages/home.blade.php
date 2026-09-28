@extends('layouts.app')

@section('title', __('storefront.home.heroTitle'))
@section('description', __('storefront.home.heroLede'))
@section('overlay-header', true)

@php
    use App\Support\Nav;

    // The hero leans on the store's own photography. Incense carries the most
    // atmospheric image in the catalogue — and its rising smoke echoes the
    // wisp in the brand mark.
    $heroCollection = collect($collections)->first(fn ($c) => in_array($c['slug'], ['Incense', 'Perfumes'], true) && $c['image'])
        ?? collect($collections)->first(fn ($c) => ! empty($c['image']));
    $heroImage = $heroCollection['image'] ?? $heroProduct?->image();
@endphp

@section('content')

    {{-- ------------------------------------------------------------ hero --}}
    <section class="hero">
        <div class="hero__media">
            @if ($heroImage)
                <img src="{{ $heroImage }}" alt="" width="1920" height="1080"
                     fetchpriority="high" decoding="async">
            @endif
        </div>

        <div class="container hero__inner">
            <div class="hero__content">
                <p class="hero__eyebrow">{{ __('storefront.home.heroEyebrow') }}</p>
                <h1 class="hero__title">{{ __('storefront.home.heroTitle') }}</h1>
                <p class="hero__lede">{{ __('storefront.home.heroLede') }}</p>
                <div class="hero__cta">
                    <a class="btn btn--on-dark btn--lg" href="{{ Nav::url('categories') }}">
                        {{ __('storefront.actions.discover') }}
                    </a>
                    <a class="btn btn--outline-on-dark btn--lg" href="{{ Nav::url('products') }}">
                        {{ __('storefront.actions.shopNow') }}
                    </a>
                </div>
            </div>
        </div>

        <span class="hero__scroll" aria-hidden="true">{{ __('storefront.home.scroll') }}</span>
    </section>

    {{-- ----------------------------------------------------- collections --}}
    @if (count($collections))
        <section class="section">
            <div class="container">
                <x-section-heading
                    :eyebrow="__('storefront.home.collectionsEyebrow')"
                    :title="__('storefront.home.collectionsTitle')"
                    :lede="__('storefront.home.collectionsLede')"
                    :href="Nav::url('categories')"
                    data-reveal/>

                @php $featuredCollections = array_slice($collections, 0, 5); @endphp

                <div class="product-grid product-grid--3" style="gap:var(--space-4)">
                    @foreach ($featuredCollections as $collection)
                        <a @class(['collection-card', 'collection-card--tall' => $loop->first, 'collection-card--bare' => ! $collection['image'],
                               'collection-card--product-shot' => ! empty($collection['imageFromProduct'])])
                           href="{{ Nav::url('categories/'.$collection['slug']) }}"
                           data-reveal data-reveal-delay="{{ $loop->index * 80 }}"
                           @if ($loop->first) style="grid-column:span 2" @endif>
                            @if ($collection['image'])
                                <img src="{{ $collection['image'] }}" alt="" loading="lazy" decoding="async"
                                     width="800" height="800">
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
            </div>
        </section>
    @endif

    {{-- ---------------------------------------------------- best sellers --}}
    @if (count($bestSellers))
        <section class="section">
            <div class="container">
                <x-section-heading
                    :eyebrow="__('storefront.home.bestEyebrow')"
                    :title="__('storefront.home.bestTitle')"
                    :lede="__('storefront.home.bestLede')"
                    :href="Nav::url('best-sellers')"
                    data-reveal/>

                <x-product-grid :products="array_slice($bestSellers, 0, 4)" rail/>
            </div>
        </section>
    @endif

    {{-- ----------------------------------------------------- brand story --}}
    <section class="section dark-section">
        <div class="container">
            <div class="split">
                <div data-reveal>
                    <p class="section-heading__eyebrow">{{ __('storefront.home.storyEyebrow') }}</p>
                    <h2 style="font-size:var(--step-display);margin-block-end:var(--space-4)">
                        {{ __('storefront.home.storyTitle') }}
                    </h2>
                    <p style="color:var(--text-on-dark-muted);max-inline-size:var(--measure);line-height:var(--leading-relaxed)">
                        {{ __('storefront.footer.about') }}
                    </p>
                    <p style="margin-block-start:var(--space-5)">
                        <a class="btn btn--outline-on-dark" href="{{ Nav::url('about-us') }}">
                            {{ __('storefront.nav.about') }}
                        </a>
                    </p>
                </div>

                <div class="split__media" data-reveal data-reveal-delay="90">
                    @if ($heroProduct?->image())
                        <img src="{{ $heroProduct->image() }}" alt="{{ $heroProduct->name() }}"
                             loading="lazy" decoding="async" width="800" height="1000">
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- --------------------------------------------------------- offers --}}
    @if (count($offers))
        <section class="section">
            <div class="container">
                <x-section-heading
                    :eyebrow="__('storefront.home.offersEyebrow')"
                    :title="__('storefront.home.offersTitle')"
                    :href="Nav::url('offers')"
                    data-reveal/>

                <x-product-grid :products="$offers" rail/>
            </div>
        </section>
    @endif

    {{-- -------------------------------------------------------- packages --}}
    @php $packages = collect(app(\App\Services\Overzaki\CatalogService::class)->all())
        ->filter(fn ($p) => $p->isBundle())->take(1)->first(); @endphp

    @if ($packages)
        <section class="section">
            <div class="container">
                <div class="split split--reverse brand-section"
                     style="padding:clamp(var(--space-6),4vw,var(--space-8));border-radius:var(--radius-md)"
                     data-reveal>
                    <div>
                        <p class="section-heading__eyebrow">{{ __('storefront.nav.packages') }}</p>
                        <h2 style="font-size:var(--step-title);color:var(--cream-400);margin-block-end:var(--space-3)">
                            {{ __('storefront.home.bundleTitle') }}
                        </h2>
                        <p style="color:rgba(243,232,200,.78);margin-block-end:var(--space-5)">
                            {{ __('storefront.home.bundleLede') }}
                        </p>
                        <a class="btn btn--on-dark" href="{{ Nav::url('packages/'.$packages->slug()) }}">
                            {{ __('storefront.bundle.title') }}
                        </a>
                    </div>

                    <div class="split__media">
                        @if ($packages->image())
                            <img src="{{ $packages->image() }}" alt="{{ $packages->name() }}"
                                 loading="lazy" decoding="async" width="800" height="1000">
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- --------------------------------------------------- new arrivals --}}
    @if (count($newArrivals))
        <section class="section">
            <div class="container">
                <x-section-heading
                    :eyebrow="__('storefront.home.newEyebrow')"
                    :title="__('storefront.home.newTitle')"
                    :href="Nav::url('products', ['sort' => 'newest'])"
                    data-reveal/>

                <x-product-grid :products="$newArrivals" rail/>
            </div>
        </section>
    @endif

@endsection
