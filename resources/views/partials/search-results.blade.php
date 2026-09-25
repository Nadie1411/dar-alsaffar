@php use App\Support\Nav; @endphp

<div data-count="{{ __('storefront.listing.count', ['count' => count($products)]) }}">
    @if (count($products))
        <p class="section-heading__eyebrow">{{ __('storefront.search.results') }}</p>

        <div style="margin-block-end:var(--space-5)">
            @foreach ($products as $product)
                <a class="search-result" href="{{ Nav::url('products/'.$product->slug()) }}">
                    @if ($product->image())
                        <img class="search-result__img" src="{{ $product->image() }}" alt=""
                             width="64" height="80" loading="lazy" decoding="async">
                    @else
                        <span class="search-result__img" aria-hidden="true"></span>
                    @endif

                    <span style="flex:1;min-inline-size:0">
                        <span class="search-result__name" style="display:block">{{ $product->name() }}</span>
                        @if ($category = $product->primaryCategory())
                            <span class="search-result__meta">{{ $category['name'] }}</span>
                        @endif
                    </span>

                    <x-price :product="$product"/>
                </a>
            @endforeach
        </div>

        <a class="btn btn--ghost btn--block" href="{{ Nav::url('search', ['q' => $term]) }}">
            {{ __('storefront.search.viewAll') }}
        </a>
    @else
        <x-empty-state icon="search"
                       :title="__('storefront.search.noResults')"
                       :text="__('storefront.search.noResultsText')"/>
    @endif
</div>
