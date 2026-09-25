@php use App\Support\Nav; @endphp

<aside class="drawer" id="cart" data-panel="cart" role="dialog" aria-modal="true"
       aria-label="{{ __('storefront.cart.title') }}" hidden>

    <div class="drawer__head">
        <h2 class="drawer__title">{{ __('storefront.cart.title') }}</h2>
        <button type="button" class="icon-btn" data-close aria-label="{{ __('storefront.nav.close') }}">
            <x-icon name="close"/>
        </button>
    </div>

    {{-- Contents are fetched on open so the drawer always shows live,
         API-priced totals rather than a stale server render. --}}
    <div class="drawer__body" data-cart-body>
        <div class="stack">
            <div class="skeleton" style="block-size:96px"></div>
            <div class="skeleton" style="block-size:96px"></div>
        </div>
    </div>
</aside>
