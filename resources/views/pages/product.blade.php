@extends('layouts.app')

@section('title', $product->name())
@section('description', $product->excerpt(160) ?: __('storefront.footer.about'))
@section('og:type', 'product')
@section('og:image', $product->image() ?? asset('assets/brand/icon-512.png'))

@php
    use App\Support\Money;
    use App\Support\Nav;

    $gallery  = $product->gallery();
    $category = $product->primaryCategory();
    $saved    = app(\App\Contracts\Store\Wishlist::class)->has($product->id());
    $maxQty   = $product->maxPerOrder() ?? $product->quantityAvailable() ?? 99;
@endphp

@push('schema')
<script type="application/ld+json">
{!! json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $product->name(),
    'image'       => $gallery ?: [asset('assets/brand/icon-512.png')],
    'description' => $product->excerpt(300),
    'sku'         => $product->sku(),
    'brand'       => ['@type' => 'Brand', 'name' => __('storefront.brand.name')],
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => url()->current(),
        'priceCurrency' => 'KWD',
        'price'         => number_format($product->price(), 3, '.', ''),
        'availability'  => $product->inStock()
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')

    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="crumbs">
                <li><a href="{{ Nav::url() }}">{{ __('storefront.nav.home') }}</a></li>
                <li><a href="{{ Nav::url('products') }}">{{ __('storefront.listing.allProducts') }}</a></li>
                @if ($category)
                    <li><a href="{{ Nav::url('categories/'.$category['slug']) }}">{{ $category['name'] }}</a></li>
                @endif
                <li><span aria-current="page">{{ $product->name() }}</span></li>
            </ol>
        </nav>

        <div class="pdp" data-product-scope>

            {{-- ------------------------------------------------- gallery --}}
            <div class="gallery @if (count($gallery) > 1) gallery--with-thumbs @endif" data-gallery>
                <div class="gallery__stage">
                    @forelse ($gallery as $i => $image)
                        <img data-gallery-slide
                             class="{{ $i === 0 ? 'is-active' : '' }}"
                             src="{{ $image }}"
                             alt="{{ $i === 0 ? $product->name() : __('storefront.product.viewImage', ['n' => $i + 1]) }}"
                             width="1000" height="1250"
                             loading="{{ $i === 0 ? 'eager' : 'lazy' }}"
                             fetchpriority="{{ $i === 0 ? 'high' : 'auto' }}"
                             decoding="async">
                    @empty
                        <span class="center muted" style="position:absolute;inset:0;display:grid;place-items:center">
                            {{ __('storefront.product.noImage') }}
                        </span>
                    @endforelse
                </div>

                @if (count($gallery) > 1)
                    <div class="gallery__thumbs" role="tablist" aria-label="{{ __('storefront.product.gallery') }}">
                        @foreach ($gallery as $i => $image)
                            <button type="button" class="gallery__thumb" data-gallery-thumb
                                    role="tab" aria-current="{{ $i === 0 ? 'true' : 'false' }}"
                                    aria-label="{{ __('storefront.product.viewImage', ['n' => $i + 1]) }}">
                                <img src="{{ $image }}" alt="" width="170" height="212" loading="lazy" decoding="async">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- ---------------------------------------------------- info --}}
            <div class="pdp__info">
                @if ($category)
                    <p class="pdp__kicker">{{ $category['name'] }}</p>
                @endif

                <h1 class="pdp__title">{{ $product->name() }}</h1>

                @if ($product->rating() > 0)
                    <p class="rating">
                        <span class="rating__stars" aria-hidden="true">
                            @for ($i = 1; $i <= 5; $i++)
                                <x-icon name="star" size="14"
                                        :style="$i <= round($product->rating()) ? '' : 'opacity:.25'"/>
                            @endfor
                        </span>
                        <span>{{ $product->rating() }} / 5</span>
                    </p>
                @endif

                <div class="pdp__price" data-price-base="{{ $product->price() }}">
                    <span data-price-display><x-price :product="$product" size="lg"/></span>
                    @if ($product->hasDiscount())
                        <p style="margin-block-start:var(--space-2)">
                            <span class="badge badge--sale">
                                {{ __('storefront.product.save') }}
                                {{ Money::format($product->savings(), $product->symbol()) }}
                            </span>
                        </p>
                    @endif
                </div>

                @if ($excerpt = $product->excerpt(220))
                    <p class="muted" style="line-height:var(--leading-relaxed)">{{ $excerpt }}</p>
                @endif

                @if ($product->inStock())
                    <x-product-options :product="$product"/>

                    <div class="pdp__actions">
                        <div class="pdp__buy-row">
                            <div class="qty">
                                <button type="button" data-qty-step="-1"
                                        aria-label="{{ __('storefront.product.decrease') }}" disabled>
                                    <x-icon name="minus" size="16"/>
                                </button>
                                <label class="visually-hidden" for="qty">{{ __('storefront.product.quantity') }}</label>
                                <input id="qty" type="number" data-qty-input value="1" min="1" max="{{ $maxQty }}"
                                       inputmode="numeric" aria-live="polite">
                                <button type="button" data-qty-step="1"
                                        aria-label="{{ __('storefront.product.increase') }}">
                                    <x-icon name="plus" size="16"/>
                                </button>
                            </div>

                            <button type="button" class="btn btn--lg btn--block"
                                    data-add-to-cart="{{ $product->id() }}"
                                    data-name="{{ $product->name() }}">
                                {{ __('storefront.actions.addToCart') }}
                            </button>
                        </div>

                        <div style="display:flex;gap:var(--space-3)">
                            <button type="button" class="btn btn--ghost"
                                    data-wishlist="{{ $product->id() }}"
                                    aria-pressed="{{ $saved ? 'true' : 'false' }}">
                                <x-icon name="heart" size="16"/>
                                {{ __('storefront.wishlist.add') }}
                            </button>
                            <button type="button" class="btn btn--ghost" data-share
                                    data-share-title="{{ $product->name() }}">
                                <x-icon name="share" size="16"/>
                                {{ __('storefront.actions.share') }}
                            </button>
                        </div>
                    </div>

                    @if ($product->isLowStock() || $product->quantityAvailable() !== null)
                        <p class="status status--pending" style="margin-block-end:var(--space-4)">
                            @if ($product->quantityAvailable() !== null)
                                {{ __('storefront.product.onlyLeft', ['count' => $product->quantityAvailable()]) }}
                            @else
                                {{ __('storefront.product.lowStock') }}
                            @endif
                        </p>
                    @endif
                @else
                    <div class="pdp__actions">
                        <p class="alert alert--notice">
                            <x-icon name="info" size="16" class="alert__icon"/>
                            {{ __('storefront.product.soldOut') }}
                        </p>
                        <button type="button" class="btn btn--ghost btn--block"
                                data-wishlist="{{ $product->id() }}"
                                aria-pressed="{{ $saved ? 'true' : 'false' }}">
                            <x-icon name="heart" size="16"/> {{ __('storefront.wishlist.add') }}
                        </button>
                    </div>
                @endif

                <p class="pdp__meta">
                    @if ($product->sku())
                        <span>{{ __('storefront.product.sku') }}: {{ $product->sku() }}</span>
                    @endif
                    @if ($product->inStock())
                        <span class="status status--done">{{ __('storefront.product.inStock') }}</span>
                    @endif
                </p>

                {{-- ------------------------------------------ accordion --}}
                <div class="accordion" style="margin-block-start:var(--space-6)">
                    @if ($product->descriptionHtml())
                        <div class="accordion__item">
                            <h2>
                                <button type="button" class="accordion__trigger"
                                        aria-expanded="true" aria-controls="acc-desc">
                                    {{ __('storefront.product.notes') }}
                                    <x-icon name="chevron" size="16" class="chev"/>
                                </button>
                            </h2>
                            <div class="accordion__panel" id="acc-desc" data-shown>
                                <div><div class="accordion__content prose">
                                    {!! $product->descriptionHtml() !!}
                                </div></div>
                            </div>
                        </div>
                    @endif

                    <div class="accordion__item">
                        <h2>
                            <button type="button" class="accordion__trigger"
                                    aria-expanded="false" aria-controls="acc-ship">
                                {{ __('storefront.product.shipping') }}
                                <x-icon name="chevron" size="16" class="chev"/>
                            </button>
                        </h2>
                        <div class="accordion__panel" id="acc-ship">
                            <div><div class="accordion__content">
                                <p>{{ __('storefront.values.deliveryText') }}</p>
                                <p><a class="link-underline" href="{{ Nav::url('shipping') }}">
                                    {{ __('storefront.content.shippingTitle') }}
                                </a></p>
                            </div></div>
                        </div>
                    </div>

                    <div class="accordion__item">
                        <h2>
                            <button type="button" class="accordion__trigger"
                                    aria-expanded="false" aria-controls="acc-ret">
                                {{ __('storefront.content.returnsTitle') }}
                                <x-icon name="chevron" size="16" class="chev"/>
                            </button>
                        </h2>
                        <div class="accordion__panel" id="acc-ret">
                            <div><div class="accordion__content">
                                <p><a class="link-underline" href="{{ Nav::url('returns') }}">
                                    {{ __('storefront.content.returnsTitle') }}
                                </a></p>
                            </div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------- related --}}
        @if (count($related))
            <section class="section">
                <x-section-heading
                    :eyebrow="__('storefront.home.bestEyebrow')"
                    :title="__('storefront.product.related')"
                    :href="Nav::url('products')"/>
                <x-product-grid :products="$related" rail/>
            </section>
        @endif
    </div>

    {{-- Sticky buy bar for phones, revealed once the price scrolls away. --}}
    @if ($product->inStock())
        <div class="buy-bar" data-buy-bar>
            <div class="buy-bar__price"><x-price :product="$product"/></div>
            <button type="button" class="btn" data-add-to-cart="{{ $product->id() }}"
                    data-name="{{ $product->name() }}">
                {{ __('storefront.actions.addToCart') }}
            </button>
        </div>
    @endif

@endsection
