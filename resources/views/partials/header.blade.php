@php
    use App\Services\Overzaki\AuthService;
    use App\Support\Nav;

    $cartCount = app(\App\Services\Overzaki\CartService::class)->count();
    $wishCount = app(\App\Services\Overzaki\WishlistService::class)->count();
    $menuCategories = app(\App\Services\Overzaki\CatalogService::class)->categoriesWithCounts();
    $featured = collect($menuCategories)->first(fn ($c) => ! empty($c['image']));
    $overlay = View::hasSection('overlay-header');
@endphp

<header @class(['header', 'header--overlay' => $overlay]) data-header>
    <div class="container">
        <div class="header__inner">

            {{-- start: menu (mobile) / brand (desktop) --}}
            <div style="display:flex;align-items:center;gap:var(--space-1)">
                <button type="button" class="icon-btn burger" data-open="mobile-menu"
                        aria-expanded="false" aria-controls="mobile-menu"
                        aria-label="{{ __('storefront.nav.menu') }}">
                    <span class="burger__lines" aria-hidden="true"><span></span><span></span><span></span></span>
                </button>

                <a class="header__brand" href="{{ Nav::url() }}" aria-label="{{ __('storefront.brand.name') }}">
                    <img class="header__logo-emerald" src="{{ asset(config('brand.logo.mark_emerald')) }}"
                         alt="{{ __('storefront.brand.name') }}" width="224" height="290">
                    <img class="header__logo-cream" src="{{ asset(config('brand.logo.mark_cream')) }}"
                         alt="{{ __('storefront.brand.name') }}" width="224" height="290">
                </a>
            </div>

            {{-- centre: primary navigation --}}
            <nav class="header__nav" aria-label="{{ __('storefront.nav.menu') }}">
                <div class="nav__item">
                    <a class="nav__link" href="{{ Nav::url() }}"
                       @if (Nav::isHome()) aria-current="page" @endif>{{ __('storefront.nav.home') }}</a>
                </div>

                <div class="nav__item">
                    <a class="nav__link" href="{{ Nav::url('products') }}"
                       @if (Nav::isActive('products')) aria-current="page" @endif>
                        {{ __('storefront.nav.perfumes') }}
                    </a>
                </div>

                {{-- Collections opens a mega menu built from the live category tree. --}}
                <div class="nav__item">
                    <a class="nav__link" href="{{ Nav::url('categories') }}"
                       @if (Nav::isActive('categories')) aria-current="page" @endif>
                        {{ __('storefront.nav.collections') }}
                        <x-icon name="chevron" size="14" class="chev"/>
                    </a>

                    @if (count($menuCategories))
                        <div class="mega">
                            <div class="mega__grid">
                                @foreach ($menuCategories as $category)
                                    <a class="mega__link" href="{{ Nav::url('categories/'.$category['slug']) }}">
                                        <span>{{ $category['name'] }}</span>
                                        <span>{{ $category['count'] }}</span>
                                    </a>
                                @endforeach
                            </div>

                            @if ($featured)
                                <a class="mega__feature" href="{{ Nav::url('categories/'.$featured['slug']) }}">
                                    <img src="{{ $featured['image'] }}" alt="" loading="lazy" decoding="async">
                                    <span>
                                        <span class="section-heading__eyebrow" style="color:var(--gold-300)">
                                            {{ __('storefront.nav.collections') }}
                                        </span>
                                        <span class="collection-card__name">{{ $featured['name'] }}</span>
                                    </span>
                                </a>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="nav__item">
                    <a class="nav__link" href="{{ Nav::url('packages') }}"
                       @if (Nav::isActive('packages')) aria-current="page" @endif>{{ __('storefront.nav.packages') }}</a>
                </div>
                <div class="nav__item">
                    <a class="nav__link" href="{{ Nav::url('best-sellers') }}"
                       @if (Nav::isActive('best-sellers')) aria-current="page" @endif>{{ __('storefront.nav.bestSellers') }}</a>
                </div>
                <div class="nav__item">
                    <a class="nav__link" href="{{ Nav::url('offers') }}"
                       @if (Nav::isActive('offers')) aria-current="page" @endif>{{ __('storefront.nav.offers') }}</a>
                </div>
                <div class="nav__item">
                    <a class="nav__link" href="{{ Nav::url('about-us') }}"
                       @if (Nav::isActive('about-us')) aria-current="page" @endif>{{ __('storefront.nav.about') }}</a>
                </div>
            </nav>

            {{-- end: utilities --}}
            <div class="header__actions">
                <button type="button" class="icon-btn" data-open="search"
                        aria-label="{{ __('storefront.actions.search') }}">
                    <x-icon name="search"/>
                </button>

                <a class="icon-btn" href="{{ AuthService::check() ? Nav::url('account') : Nav::url('login') }}"
                   aria-label="{{ __('storefront.actions.account') }}">
                    <x-icon name="user"/>
                </a>

                <a class="icon-btn" href="{{ Nav::url('wishlist') }}"
                   aria-label="{{ __('storefront.actions.wishlist') }}">
                    <x-icon name="heart"/>
                    <span class="icon-btn__badge" data-wishlist-count @if (! $wishCount) hidden @endif>{{ $wishCount }}</span>
                </a>

                {{-- A link, not a button: the script intercepts it to open the
                     drawer, and without JavaScript it still reaches the cart. --}}
                <a class="icon-btn" href="{{ Nav::url('cart') }}" data-open="cart"
                   aria-label="{{ __('storefront.actions.cart') }}">
                    <x-icon name="bag"/>
                    <span class="icon-btn__badge" data-cart-count @if (! $cartCount) hidden @endif>{{ $cartCount }}</span>
                </a>

                <span class="lang" style="margin-inline-start:var(--space-2)">
                    <a href="{{ Nav::switchTo('ar-KW') }}" hreflang="ar"
                       aria-current="{{ $localeCode === 'ar-KW' ? 'true' : 'false' }}"><span>ع</span></a>
                    <a href="{{ Nav::switchTo('en-KW') }}" hreflang="en"
                       aria-current="{{ $localeCode === 'en-KW' ? 'true' : 'false' }}"><span>EN</span></a>
                </span>
            </div>
        </div>
    </div>
</header>
