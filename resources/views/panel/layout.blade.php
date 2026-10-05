@php
    use App\Enums\PanelModule;
    use App\Support\PanelNav;
    use Illuminate\Support\Facades\Auth;

    $user = Auth::guard('staff')->user();
    $groups = PanelNav::for($user);
    $other = $locale === 'ar' ? 'en' : 'ar';
    $settings = app(\App\Services\Settings::class);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('panel.app')) — {{ __('panel.app') }}</title>
    <link rel="icon" href="{{ asset('favicon.png') }}" sizes="32x32">
    <link rel="manifest" href="{{ route('panel.manifest', [], false) }}">
    <meta name="theme-color" content="#0f1412">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ config('brand.name.'.$locale, config('brand.name.ar')) }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/tokens.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/panel.css') }}">
    @stack('head')
</head>
<body class="panel"
      data-worker="{{ route('panel.worker', [], false) }}"
      @if ($user->canAccess(PanelModule::Orders))
          data-orders-feed="{{ route('panel.orders.feed') }}"
          data-orders-poll="{{ $settings->int('orders.poll', 30) }}"
          data-orders-sound="{{ $settings->bool('orders.alert', true) ? 1 : 0 }}"
      @endif>
<div class="panel-shell">
    <aside class="panel-side" id="panel-side" aria-label="{{ __('panel.nav.label') }}">
        <a class="panel-brand" href="{{ route('panel.dashboard') }}">
            <img src="{{ asset(config('brand.logo.mark_cream')) }}" alt="">
            <span class="panel-brand__name">{{ config('brand.name.'.$locale, config('brand.name.ar')) }}
                <span class="panel-brand__sub">{{ __('panel.app') }}</span></span>
        </a>

        <nav class="panel-nav">
            @foreach ($groups as $group => $links)
                <p class="panel-nav__group">{{ __('panel.nav.'.$group) }}</p>
                @foreach ($links as $link)
                    <a class="panel-nav__link {{ $link['active'] ? 'is-active' : '' }}" href="{{ $link['url'] }}"
                       @if ($link['active']) aria-current="page" @endif>
                        <x-panel.icon :name="$link['icon']"/>
                        <span>{{ $link['label'] }}</span>
                        @if ($link['module'] === 'orders')
                            <span class="panel-nav__count" data-new-orders @if ($newOrders < 1) hidden @endif>{{ $newOrders }}</span>
                        @endif
                        @if ($link['module'] === 'inbox' && $unreadMessages > 0)
                            <span class="panel-nav__count">{{ $unreadMessages }}</span>
                        @endif
                    </a>
                @endforeach
            @endforeach
        </nav>

        <a class="panel-side__foot" href="{{ route('panel.profile.edit') }}" title="{{ __('panel.profile.title') }}">
            <span class="panel-avatar" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
            <span class="panel-side__who">
                <strong>{{ $user->name }}</strong>
                <span>{{ $user->role->label() }}</span>
            </span>
        </a>
    </aside>

    <div class="panel-main">
        <header class="panel-top">
            <button class="icon-btn panel-top__menu" type="button" data-toggle-side aria-label="{{ __('panel.nav.menu') }}">
                <x-panel.icon name="menu"/>
            </button>
            <h2 class="panel-top__title">@yield('title')</h2>

            <div class="panel-top__tools">
                <a class="icon-btn" href="{{ url('/') }}" target="_blank" rel="noopener" title="{{ __('panel.top.viewStore') }}">
                    <x-panel.icon name="bag"/><span class="visually-hidden">{{ __('panel.top.viewStore') }}</span>
                </a>
                @if ($user->canAccess(PanelModule::Orders))
                    <a class="icon-btn" href="{{ route('panel.orders.index') }}" title="{{ __('panel.top.newOrders') }}">
                        <x-panel.icon name="bell"/>
                        <span class="dot" data-new-orders @if ($newOrders < 1) hidden @endif>{{ $newOrders }}</span>
                        <span class="visually-hidden">{{ __('panel.top.newOrders') }}</span>
                    </a>
                @endif
                <a class="icon-btn" href="{{ route('panel.language', $other) }}" title="{{ __('panel.top.language') }}">
                    <x-panel.icon name="globe"/><span class="visually-hidden">{{ __('panel.top.language') }}</span>
                </a>
                <form method="POST" action="{{ route('panel.logout') }}">
                    @csrf
                    <button class="icon-btn" type="submit" title="{{ __('panel.top.logout') }}">
                        <x-panel.icon name="logout"/><span class="visually-hidden">{{ __('panel.top.logout') }}</span>
                    </button>
                </form>
            </div>
        </header>

        <main class="panel-content" id="main">
            <x-panel.alerts/>
            @yield('content')
        </main>
    </div>
</div>

<div class="panel-scrim" data-scrim hidden></div>
<div class="panel-toasts" data-toasts aria-live="polite"></div>
@include('panel.partials.install')

<dialog class="confirm" id="confirm-dialog">
    <form method="dialog">
        <div class="confirm__body">
            <h3 data-confirm-title>{{ __('panel.common.sure') }}</h3>
            <p class="muted" data-confirm-text></p>
        </div>
        <div class="confirm__foot">
            <button class="btn-p btn-p--ghost" value="cancel">{{ __('panel.common.cancel') }}</button>
            <button class="btn-p" value="ok" data-confirm-ok>{{ __('panel.common.confirm') }}</button>
        </div>
    </form>
</dialog>

<script src="{{ \App\Support\Asset::url('assets/js/panel.js') }}" defer></script>
@stack('scripts')
</body>
</html>
