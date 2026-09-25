@extends('layouts.app')

@section('title', __('storefront.content.aboutTitle'))
@section('description', __('storefront.footer.about'))

@php use App\Support\Nav; @endphp

@section('content')

    {{-- --------------------------------------------------------- opener --}}
    <section class="brand-section" style="padding-block:var(--section-y)">
        <div class="container">
            <div class="split">
                <div data-reveal>
                    <p class="section-heading__eyebrow">{{ __('storefront.brand.tagline') }}</p>
                    <h1 style="font-size:var(--step-display);color:var(--cream-400);margin-block-end:var(--space-4)">
                        {{ __('storefront.content.aboutTitle') }}
                    </h1>
                    <p style="color:rgba(243,232,200,.82);line-height:var(--leading-relaxed);max-inline-size:var(--measure)">
                        {{ __('storefront.footer.about') }}
                    </p>
                </div>

                <div class="split__media" data-reveal data-reveal-delay="80">
                    <img src="{{ asset('assets/brand/logo-green.jpg') }}"
                         alt="{{ __('storefront.brand.name') }}" width="960" height="1280" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    <div class="container container--narrow section">

        {{-- The brand has not published a story, philosophy or quality
             statement anywhere we can read. The layout is built and waiting;
             nothing is invented to fill it. --}}
        <p class="placeholder-note">
            <x-icon name="info" size="18"/>
            <span>{{ __('storefront.content.contentPending') }}</span>
        </p>

        <div class="prose">
            <h2>{{ __('storefront.content.storyTitle') }}</h2>
            <p class="muted"><em>{{ __('storefront.content.contentPending') }}</em></p>

            <h2>{{ __('storefront.content.philosophyTitle') }}</h2>
            <p class="muted"><em>{{ __('storefront.content.contentPending') }}</em></p>

            <h2>{{ __('storefront.content.qualityTitle') }}</h2>
            <p class="muted"><em>{{ __('storefront.content.contentPending') }}</em></p>
        </div>
    </div>

    {{-- ---------------------------------------------------- collections --}}
    @if (count($collections))
        <section class="section">
            <div class="container">
                <x-section-heading
                    :eyebrow="__('storefront.content.houseTitle')"
                    :title="__('storefront.home.collectionsTitle')"
                    :href="Nav::url('categories')"/>

                <div class="product-grid product-grid--3" style="gap:var(--space-4)">
                    @foreach (array_slice($collections, 0, 3) as $collection)
                        <a @class(['collection-card', 'collection-card--bare' => ! $collection['image'],
                               'collection-card--product-shot' => ! empty($collection['imageFromProduct'])]) href="{{ Nav::url('categories/'.$collection['slug']) }}" data-reveal>
                            @if ($collection['image'])
                                <img src="{{ $collection['image'] }}" alt="" width="800" height="800" loading="lazy">
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

    {{-- -------------------------------------------------------- contact --}}
    <section class="section--tight">
        <div class="container center">
            <p class="section-heading__eyebrow" style="justify-content:center">
                {{ __('storefront.content.contactTitle') }}
            </p>
            <p class="muted" style="margin-block-end:var(--space-5)">{{ __('storefront.content.contactLede') }}</p>
            <a class="btn" href="{{ Nav::url('contact-us') }}">{{ __('storefront.nav.contact') }}</a>
        </div>
    </section>

@endsection
