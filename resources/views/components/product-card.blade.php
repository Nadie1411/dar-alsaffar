@props([
    'product',
    'eager' => false,
    'heading' => 'h3',
])

@php
    use App\Support\Nav;

    $url      = Nav::url('products/'.$product->slug());
    $image    = $product->image();
    $hover    = $product->hoverImage();
    $inStock  = $product->inStock();
    $saved    = app(\App\Services\Overzaki\WishlistService::class)->has($product->id());
    $category = $product->primaryCategory();
@endphp

<article class="product-card" data-product-card>
    <div class="product-card__media">
        <a href="{{ $url }}" tabindex="-1" aria-hidden="true">
            @if ($image)
                <img class="product-card__img product-card__img--main"
                     src="{{ $image }}"
                     alt=""
                     width="600" height="750"
                     loading="{{ $eager ? 'eager' : 'lazy' }}"
                     fetchpriority="{{ $eager ? 'high' : 'auto' }}"
                     decoding="async">
                @if ($hover)
                    <img class="product-card__img product-card__img--hover"
                         src="{{ $hover }}" alt="" width="600" height="750"
                         loading="lazy" decoding="async">
                @endif
            @else
                <span class="product-card__img product-card__img--main" aria-hidden="true"></span>
            @endif
        </a>

        <div class="product-card__flags">
            @if ($product->hasDiscount())
                {{-- Written label plus figure: the discount is never colour alone. --}}
                <span class="badge badge--sale">
                    {{ __('storefront.product.off') }} {{ $product->discountPercent() }}%
                </span>
            @endif
            @if ($product->isNew())
                <span class="badge badge--new">{{ __('storefront.product.new') }}</span>
            @endif
            @if ($inStock && $product->isLowStock())
                <span class="badge badge--quiet">{{ __('storefront.product.lowStock') }}</span>
            @endif
        </div>

        <button type="button"
                class="product-card__wish"
                data-wishlist="{{ $product->id() }}"
                aria-pressed="{{ $saved ? 'true' : 'false' }}"
                aria-label="{{ $saved ? __('storefront.wishlist.remove') : __('storefront.wishlist.add') }}">
            <x-icon name="heart" size="18"/>
        </button>

        @if ($inStock)
            <div class="product-card__reveal">
                @if ($product->isPricedByOptions() || $product->hasOptions() || $product->isBundle())
                    {{-- Needs a choice (a weight, a package selection), so the
                         card sends the shopper to the product rather than
                         quietly adding the wrong thing. --}}
                    <a class="btn btn--sm btn--block"
                       href="{{ $product->isBundle() ? Nav::url('packages/'.$product->slug()) : $url }}">
                        {{ $product->isBundle() ? __('storefront.bundle.choose') : __('storefront.actions.quickView') }}
                    </a>
                @else
                    <button type="button"
                            class="btn btn--sm btn--block"
                            data-add-to-cart="{{ $product->id() }}"
                            data-name="{{ $product->name() }}">
                        {{ __('storefront.actions.addToCart') }}
                    </button>
                @endif
            </div>
        @else
            <p class="product-card__soldout">{{ __('storefront.product.soldOut') }}</p>
        @endif
    </div>

    <div class="product-card__body">
        @if ($category)
            <p class="product-card__kicker">{{ $category['name'] }}</p>
        @endif

        <{{ $heading }} class="product-card__name">
            <a href="{{ $url }}">{{ $product->name() }}</a>
        </{{ $heading }}>

        <x-price :product="$product"/>
    </div>
</article>
