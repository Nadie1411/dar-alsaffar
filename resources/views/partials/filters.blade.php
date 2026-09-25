@props(['categories', 'filters', 'priceRange', 'idPrefix' => 'f'])

{{-- One markup block serves the desktop sidebar and the mobile sheet; the
     ids are prefixed so both can exist without colliding. --}}

<div class="filter-group">
    <h3 class="filter-group__title" id="{{ $idPrefix }}-cat">{{ __('storefront.listing.filterGroup') }}</h3>
    <div role="group" aria-labelledby="{{ $idPrefix }}-cat">
        @foreach ($categories as $category)
            <label class="filter-option">
                <input type="checkbox" name="category[]" value="{{ $category['slug'] }}"
                       @checked(in_array($category['slug'], (array) ($filters['category'] ?? []), true))>
                <span>{{ $category['name'] }}</span>
                <span class="filter-option__count">{{ $category['count'] }}</span>
            </label>
        @endforeach
    </div>
</div>

<div class="filter-group">
    <h3 class="filter-group__title" id="{{ $idPrefix }}-price">{{ __('storefront.listing.filterPrice') }}</h3>
    <div class="price-range" role="group" aria-labelledby="{{ $idPrefix }}-price">
        <label class="visually-hidden" for="{{ $idPrefix }}-min">{{ __('storefront.listing.priceMin') }}</label>
        <input class="input" id="{{ $idPrefix }}-min" type="number" name="min" inputmode="numeric"
               min="{{ $priceRange['min'] }}" max="{{ $priceRange['max'] }}"
               placeholder="{{ $priceRange['min'] }}" value="{{ $filters['min'] ?? '' }}">
        <span aria-hidden="true" class="muted">—</span>
        <label class="visually-hidden" for="{{ $idPrefix }}-max">{{ __('storefront.listing.priceMax') }}</label>
        <input class="input" id="{{ $idPrefix }}-max" type="number" name="max" inputmode="numeric"
               min="{{ $priceRange['min'] }}" max="{{ $priceRange['max'] }}"
               placeholder="{{ $priceRange['max'] }}" value="{{ $filters['max'] ?? '' }}">
    </div>
    <p class="field__hint">{{ __('storefront.currency.kwd') }}</p>
</div>

<div class="filter-group">
    <h3 class="filter-group__title">{{ __('storefront.listing.filterStock') }}</h3>
    <label class="filter-option">
        <input type="checkbox" name="availability" value="in"
               @checked(($filters['availability'] ?? null) === 'in')>
        <span>{{ __('storefront.listing.inStockOnly') }}</span>
    </label>
    <label class="filter-option">
        <input type="checkbox" name="offers" value="1" @checked(! empty($filters['offers']))>
        <span>{{ __('storefront.listing.onSaleOnly') }}</span>
    </label>
</div>
