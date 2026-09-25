@extends('admin._layout')
@section('title', __('storefront.admin.ordersTitle'))

@section('body')
<header class="admin-bar">
    <div class="admin-bar__inner">
        <span class="admin-bar__brand">
            <img src="{{ asset(config('brand.logo.mark_emerald')) }}" alt="" width="224" height="290">
            <span>{{ __('storefront.admin.ordersTitle') }}</span>
        </span>

        <span class="admin-bar__actions">
            <a class="btn btn--ghost btn--sm" href="/admin">{{ __('storefront.admin.title') }}</a>
            <form method="POST" action="/admin/logout">
                @csrf
                <button class="btn btn--ghost btn--sm" type="submit">{{ __('storefront.admin.signOut') }}</button>
            </form>
        </span>
    </div>
</header>

<main class="admin-main"
      data-order-board
      data-poll="{{ $pollSeconds }}"
      data-alert="{{ $alertEnabled ? '1' : '0' }}">

    {{-- The ringing banner. Hidden until the feed reports something unseen. --}}
    <div class="order-alert" data-alert-banner @if (! $unseenCount) hidden @endif role="alert" aria-live="assertive">
        <span class="order-alert__pulse" aria-hidden="true"></span>

        <div class="order-alert__text">
            <strong data-alert-count>
                @if ($unseenCount === 1)
                    {{ __('storefront.admin.ordersNewOne') }}
                @else
                    {{ __('storefront.admin.ordersNewCount', ['count' => $unseenCount]) }}
                @endif
            </strong>
        </div>

        <form method="POST" action="/admin/orders/seen" data-seen-form>
            @csrf
            <button class="btn btn--on-dark" type="submit">{{ __('storefront.admin.ordersSeen') }}</button>
        </form>
    </div>

    {{-- Browsers refuse to play audio until the page has been interacted with,
         so the sound has to be armed by hand once per session. --}}
    <div class="admin-card" data-arm-card>
        <div class="order-arm">
            <button class="btn" type="button" data-arm>
                <x-icon name="clock" size="16"/> {{ __('storefront.admin.ordersArm') }}
            </button>
            <span class="status status--done" data-armed hidden>{{ __('storefront.admin.ordersArmed') }}</span>
        </div>
        <p class="admin-card__hint" style="margin-block:var(--space-3) 0">
            {{ __('storefront.admin.ordersArmHint') }}
        </p>
        <p class="admin-card__hint" style="margin:0">{{ __('storefront.admin.ordersTabHint') }}</p>
    </div>

    <section class="admin-card">
        <h2 class="admin-card__title">{{ __('storefront.admin.ordersTitle') }}</h2>
        <p class="admin-card__hint">{{ __('storefront.admin.ordersHint') }}</p>

        <p class="admin-card__hint" data-feed-error hidden style="color:var(--danger)">
            {{ __('storefront.admin.ordersOffline') }}
        </p>

        <div data-order-list>
            @include('admin._order-rows', ['orders' => $orders])
        </div>
    </section>
</main>

<script>
window.OrderBoard = {
    feed: '/admin/orders/feed',
    i18n: {
        one: @json(__('storefront.admin.ordersNewOne')),
        many: @json(__('storefront.admin.ordersNewCount', ['count' => '%n'])),
    },
};
</script>
<script src="{{ \App\Support\Asset::url('assets/js/admin-orders.js') }}" defer></script>
@endsection
