@php
    use App\Services\Overzaki\AuthService;
    use App\Support\Nav;
    $categories = app(\App\Services\Overzaki\CatalogService::class)->categoriesWithCounts();
@endphp

<aside class="drawer drawer--start" id="mobile-menu" data-panel="mobile-menu" role="dialog" aria-modal="true"
       aria-label="{{ __('storefront.nav.menu') }}" hidden>

    <div class="drawer__head">
        <img src="{{ asset(config('brand.logo.mark_emerald')) }}" alt="{{ __('storefront.brand.name') }}"
             width="224" height="290" style="block-size:38px;inline-size:auto">
        <button type="button" class="icon-btn" data-close aria-label="{{ __('storefront.nav.close') }}">
            <x-icon name="close"/>
        </button>
    </div>

    <nav class="drawer__body" aria-label="{{ __('storefront.nav.menu') }}">
        <a class="mnav__link" href="{{ Nav::url() }}">{{ __('storefront.nav.home') }}</a>
        <a class="mnav__link" href="{{ Nav::url('products') }}">{{ __('storefront.nav.perfumes') }}</a>

        <div>
            <a class="mnav__link" href="{{ Nav::url('categories') }}">
                {{ __('storefront.nav.collections') }}
                <x-icon name="arrow" size="16" class="icon-arrow"/>
            </a>
            <div class="mnav__sub">
                @foreach ($categories as $category)
                    <a href="{{ Nav::url('categories/'.$category['slug']) }}">
                        {{ $category['name'] }} <span class="muted">({{ $category['count'] }})</span>
                    </a>
                @endforeach
            </div>
        </div>

        <a class="mnav__link" href="{{ Nav::url('packages') }}">{{ __('storefront.nav.packages') }}</a>
        <a class="mnav__link" href="{{ Nav::url('best-sellers') }}">{{ __('storefront.nav.bestSellers') }}</a>
        <a class="mnav__link" href="{{ Nav::url('offers') }}">{{ __('storefront.nav.offers') }}</a>
        <a class="mnav__link" href="{{ Nav::url('about-us') }}">{{ __('storefront.nav.about') }}</a>
        <a class="mnav__link" href="{{ Nav::url('contact-us') }}">{{ __('storefront.nav.contact') }}</a>
    </nav>

    <div class="drawer__foot stack">
        @if (AuthService::check())
            <a class="btn btn--block" href="{{ Nav::url('account') }}">{{ __('storefront.account.title') }}</a>
            <form method="POST" action="{{ Nav::url('logout') }}">
                @csrf
                <button class="btn btn--ghost btn--block" type="submit">{{ __('storefront.auth.logout') }}</button>
            </form>
        @else
            <a class="btn btn--block" href="{{ Nav::url('login') }}">{{ __('storefront.auth.login') }}</a>
            <a class="btn btn--ghost btn--block" href="{{ Nav::url('register') }}">{{ __('storefront.auth.register') }}</a>
        @endif

        <div class="lang" style="justify-content:center">
            <a href="{{ Nav::switchTo('ar-KW') }}" aria-current="{{ $localeCode === 'ar-KW' ? 'true' : 'false' }}"><span>العربية</span></a>
            <a href="{{ Nav::switchTo('en-KW') }}" aria-current="{{ $localeCode === 'en-KW' ? 'true' : 'false' }}"><span>English</span></a>
        </div>
    </div>
</aside>
