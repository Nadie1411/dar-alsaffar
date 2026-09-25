@props([
    'product' => null,
    'now' => null,
    'was' => null,
    'symbol' => null,
    'size' => null,
])

@php
    use App\Support\Money;

    // A product whose price lives in its options is shown as a "from" price,
    // never as zero.
    $fromPrice = $product?->isPricedByOptions() ?? false;

    $current = $now ?? ($fromPrice ? $product->startingPrice() : ($product?->price() ?? 0));
    $original = $was ?? ($product?->hasDiscount() && ! $fromPrice ? $product->listPrice() : null);
    $sym = $symbol ?? $product?->symbol();
    $onSale = $original !== null && $original > $current;
@endphp

<span {{ $attributes->class(['price', 'price--lg' => $size === 'lg', 'price--sale' => $onSale]) }}>
    @if ($fromPrice)
        <span class="price__from">{{ __('storefront.product.from') }}</span>
    @endif

    {{-- The symbol travels with the element so client-side price updates
         never have to guess it. --}}
    <span class="price__now" data-symbol="{{ Money::symbol($sym) }}">{{ Money::format($current, $sym) }}</span>

    @if ($onSale)
        {{-- The old price is announced as such, not left to the strikethrough alone. --}}
        <span class="price__was">
            <span class="visually-hidden">{{ __('storefront.product.was') }}</span>
            {{ Money::format($original, $sym) }}
        </span>
    @endif
</span>
