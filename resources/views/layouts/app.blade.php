<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#00603a">

    <title>@hasSection('title')@yield('title') — {{ __('storefront.brand.name') }}@else{{ __('storefront.brand.name') }} — {{ __('storefront.brand.tagline') }}@endif</title>
    <meta name="description" content="@yield('description', __('storefront.footer.about'))">

    <link rel="canonical" href="@yield('canonical', url()->current())">
    <link rel="alternate" hreflang="ar-KW" href="{{ \App\Support\Nav::switchTo('ar-KW') }}">
    <link rel="alternate" hreflang="en-KW" href="{{ \App\Support\Nav::switchTo('en-KW') }}">
    <link rel="alternate" hreflang="x-default" href="{{ \App\Support\Nav::switchTo('ar-KW') }}">

    {{-- Open Graph --}}
    <meta property="og:type" content="@yield('og:type', 'website')">
    <meta property="og:site_name" content="{{ __('storefront.brand.name') }}">
    <meta property="og:title" content="@yield('title', __('storefront.brand.name'))">
    <meta property="og:description" content="@yield('description', __('storefront.footer.about'))">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_KW' : 'en_KW' }}">
    <meta property="og:image" content="@yield('og:image', asset('assets/brand/icon-512.png'))">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ __('storefront.brand.short') }}">
    <link rel="icon" href="{{ asset('favicon.png') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    {{-- Fonts: Amiri for Arabic display, Cormorant for Latin display,
         IBM Plex Sans Arabic for everything that has to stay readable. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://overzaki.fra1.cdn.digitaloceanspaces.com" crossorigin>
    <link rel="dns-prefetch" href="https://overzaki.fra1.cdn.digitaloceanspaces.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cormorant+Garamond:wght@300;400;500&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/tokens.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/base.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/components.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/pages.css') }}">

    @stack('head')

    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@type'    => 'Store',
        'name'     => __('storefront.brand.name'),
        'url'      => url('/'.$localeCode),
        'logo'     => asset(config('brand.logo.full_emerald')),
        'telephone'=> config('brand.contact.phone'),
        'address'  => ['@type' => 'PostalAddress', 'addressCountry' => 'KW'],
        'sameAs'   => array_values(array_filter(config('brand.social'))),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
    @stack('schema')
</head>
<body @class(['has-overlay-header' => View::hasSection('overlay-header')])>

    <a class="skip-link" href="#main">{{ __('storefront.nav.skip') }}</a>

    @php $headlineOffer = app(\App\Services\Overzaki\PromotionService::class)->headline(); @endphp
    @include('partials.offer-strip', ['offer' => $headlineOffer])
    @include('partials.header')

    <main id="main">
        @yield('content')
    </main>

    @include('partials.footer')

    @include('partials.search-overlay')
    @include('partials.cart-drawer')
    @include('partials.mobile-menu')
    @include('partials.promo-popup', ['offer' => $headlineOffer])
    @include('partials.install-prompt')

    <div class="scrim" data-scrim hidden></div>
    <div class="toast-stack" data-toasts aria-live="polite" aria-atomic="false"></div>

    <a class="wa-float" href="https://wa.me/{{ config('brand.contact.whatsapp') }}"
       target="_blank" rel="noopener" aria-label="{{ __('storefront.content.whatsapp') }}">
        <x-icon name="whatsapp" size="24"/>
    </a>

    <script>
        window.Store = {
            locale: @json($localeCode),
            dir: @json($dir),
            routes: {
                cartAdd:    @json(\App\Support\Nav::url('cart/add')),
                cartDrawer: @json(\App\Support\Nav::url('cart/drawer')),
                wishlist:   @json(\App\Support\Nav::url('wishlist')),
                suggest:    @json(\App\Support\Nav::url('search/suggest')),
                search:     @json(\App\Support\Nav::url('search')),
            },
            csrf: @json(csrf_token()),
            i18n: {
                added:     @json(__('storefront.actions.added')),
                adding:    @json(__('storefront.actions.adding')),
                wishAdd:   @json(__('storefront.wishlist.added')),
                wishRemove:@json(__('storefront.wishlist.removed')),
                error:     @json(__('storefront.errors.generic')),
                copied:    @json(__('storefront.actions.linkCopied')),
                installed: @json(__('storefront.pwa.installed')),
            },
        };
    </script>
    <script src="{{ \App\Support\Asset::url('assets/js/store.js') }}" defer></script>
    <script src="{{ \App\Support\Asset::url('assets/js/promo.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
