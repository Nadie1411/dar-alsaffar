@php
    use App\Support\Nav;
    $catalog = app(\App\Services\Overzaki\CatalogService::class);
    $searchCategories = $catalog->categoriesWithCounts();
    $suggested = $catalog->bestSellers(4);
@endphp

<div class="search-overlay" id="search" data-panel="search" role="dialog" aria-modal="true"
     aria-label="{{ __('storefront.actions.search') }}" hidden>

    <div class="search-overlay__head">
        <div class="container">
            <form class="search-overlay__bar" role="search" method="GET" action="{{ Nav::url('search') }}">
                <x-icon name="search" size="22" style="flex:none;color:var(--text-muted)"/>

                <label class="visually-hidden" for="search-input">{{ __('storefront.search.title') }}</label>
                <input class="search-overlay__input"
                       id="search-input"
                       type="search"
                       name="q"
                       autocomplete="off"
                       data-search-input
                       placeholder="{{ __('storefront.search.title') }}"
                       aria-describedby="search-status">

                <button type="button" class="icon-btn" data-close
                        aria-label="{{ __('storefront.nav.close') }}">
                    <x-icon name="close"/>
                </button>
            </form>
        </div>
    </div>

    <div class="search-overlay__body">
        <div class="container">
            <p id="search-status" class="visually-hidden" aria-live="polite"></p>

            {{-- Results replace this block as the shopper types. --}}
            <div data-search-results hidden></div>

            <div data-search-idle>
                <div data-recent-wrap hidden style="margin-block-end:var(--space-6)">
                    <div class="section-heading" style="margin-block-end:var(--space-3)">
                        <p class="section-heading__eyebrow">{{ __('storefront.search.recent') }}</p>
                        <button type="button" class="link-underline" data-clear-recent>
                            {{ __('storefront.search.clearRecent') }}
                        </button>
                    </div>
                    <div class="search-chips" data-recent></div>
                </div>

                <div style="margin-block-end:var(--space-6)">
                    <p class="section-heading__eyebrow">{{ __('storefront.search.categories') }}</p>
                    <div class="search-chips">
                        @foreach ($searchCategories as $category)
                            <a class="chip" href="{{ Nav::url('categories/'.$category['slug']) }}">
                                {{ $category['name'] }}
                            </a>
                        @endforeach
                    </div>
                </div>

                @if (count($suggested))
                    <p class="section-heading__eyebrow">{{ __('storefront.search.suggested') }}</p>
                    <div class="product-grid">
                        @foreach ($suggested as $product)
                            <x-product-card :product="$product"/>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
