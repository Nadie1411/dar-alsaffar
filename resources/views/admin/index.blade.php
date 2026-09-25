@extends('admin._layout')
@section('title', __('storefront.admin.title'))

@section('body')
<header class="admin-bar">
    <div class="admin-bar__inner">
        <span class="admin-bar__brand">
            <img src="{{ asset(config('brand.logo.mark_emerald')) }}" alt="" width="224" height="290">
            <span>{{ __('storefront.admin.title') }}</span>
        </span>

        <span class="admin-bar__actions">
            <a class="btn btn--ghost btn--sm" href="/ar-KW" target="_blank" rel="noopener">
                {{ __('storefront.admin.viewSite') }}
            </a>
            <form method="POST" action="/admin/logout">
                @csrf
                <button class="btn btn--ghost btn--sm" type="submit">{{ __('storefront.admin.signOut') }}</button>
            </form>
        </span>
    </div>
</header>

<main class="admin-main">
    @if (session('status'))
        <p class="alert alert--success" role="status">
            <x-icon name="check" size="16" class="alert__icon"/> {{ session('status') }}
        </p>
    @endif

    {{-- What this panel deliberately does not control. --}}
    <p class="alert alert--notice">
        <x-icon name="info" size="16" class="alert__icon"/>
        {{ __('storefront.admin.managedElsewhere') }}
    </p>

    {{-- ------------------------------------------------- live offers --}}
    <section class="admin-card">
        <h2 class="admin-card__title">{{ __('storefront.admin.offersSection') }}</h2>
        <p class="admin-card__hint">{{ __('storefront.admin.offersHint') }}</p>

        @forelse ($liveOffers as $offer)
            <div class="admin-offer">
                <x-icon :name="$offer['type'] === 'buy_x_get_y' ? 'gift' : 'sparkle'" size="18"
                        style="color:var(--gold-600);flex:none"/>
                <span style="flex:1;min-inline-size:0">
                    <strong style="display:block;font-weight:500">{{ $offer['headline'] }}</strong>
                    <span class="admin-card__hint" style="margin:0">{{ $offer['scope']['label'] }}</span>
                </span>
                <span class="badge {{ $offer['code'] ? 'badge--quiet' : 'badge--new' }}">
                    {{ $offer['code'] ? __('storefront.admin.offerCode').' '.$offer['code'] : __('storefront.admin.offerAuto') }}
                </span>
            </div>
        @empty
            <p class="admin-empty">
                {{ __('storefront.admin.noOffers') }}
                <span class="admin-card__hint" style="display:block;margin-block-start:var(--space-2)">
                    {{ __('storefront.admin.noOffersHint') }}
                </span>
            </p>
        @endforelse
    </section>

    <form method="POST" action="/admin" enctype="multipart/form-data">
        @csrf

        {{-- ----------------------------------------------- offer strip --}}
        <section class="admin-card">
            <h2 class="admin-card__title">{{ __('storefront.admin.stripSection') }}</h2>
            <p class="admin-card__hint">{{ __('storefront.admin.stripHint') }}</p>

            <label class="admin-toggle">
                <input type="checkbox" name="strip_enabled" value="1"
                       @checked($settings->bool('strip.enabled'))>
                <span>{{ __('storefront.admin.stripEnabled') }}</span>
            </label>
        </section>

        {{-- ---------------------------------------------------- pop-up --}}
        <section class="admin-card">
            <h2 class="admin-card__title">{{ __('storefront.admin.popupSection') }}</h2>
            <p class="admin-card__hint">{{ __('storefront.admin.popupHint') }}</p>

            <label class="admin-toggle">
                <input type="checkbox" name="popup_enabled" value="1"
                       @checked($settings->bool('popup.enabled'))>
                <span>{{ __('storefront.admin.popupEnabled') }}</span>
            </label>

            <label class="field">
                <span class="field__label">{{ __('storefront.admin.popupTitle') }}</span>
                <input class="input" type="text" name="popup_title" maxlength="120"
                       placeholder="{{ __('storefront.admin.popupTitlePh') }}"
                       value="{{ old('popup_title', $settings->get('popup.title', '')) }}">
                @error('popup_title')<span class="field__error">{{ $message }}</span>@enderror
            </label>

            <label class="field">
                <span class="field__label">{{ __('storefront.admin.popupBody') }}</span>
                <textarea class="textarea" name="popup_body" rows="3" maxlength="400"
                          placeholder="{{ __('storefront.admin.popupBodyPh') }}">{{ old('popup_body', $settings->get('popup.body', '')) }}</textarea>
                @error('popup_body')<span class="field__error">{{ $message }}</span>@enderror
            </label>

            <div class="field">
                <span class="field__label">{{ __('storefront.admin.popupImage') }}</span>

                @if ($current = $settings->get('popup.image', ''))
                    <div class="admin-thumb">
                        <img src="{{ asset($current) }}" alt="" width="160" height="90">
                        <label class="checkbox">
                            <input type="checkbox" name="remove_image" value="1">
                            <span>{{ __('storefront.admin.removeImage') }}</span>
                        </label>
                    </div>
                @endif

                <input class="input" type="file" name="popup_image" accept="image/jpeg,image/png,image/webp">
                <span class="field__hint">{{ __('storefront.admin.popupImageHint') }}</span>
                @error('popup_image')<span class="field__error">{{ $message }}</span>@enderror
            </div>

            <div class="admin-grid">
                <label class="field">
                    <span class="field__label">{{ __('storefront.admin.popupCta') }}</span>
                    <input class="input" type="text" name="popup_cta_label" maxlength="60"
                           placeholder="{{ __('storefront.admin.popupCtaPh') }}"
                           value="{{ old('popup_cta_label', $settings->get('popup.cta_label', '')) }}">
                </label>

                <label class="field">
                    <span class="field__label">{{ __('storefront.admin.popupPath') }}</span>
                    <input class="input" type="text" name="popup_cta_path" dir="ltr" maxlength="80"
                           placeholder="offers"
                           value="{{ old('popup_cta_path', $settings->get('popup.cta_path', '')) }}">
                    <span class="field__hint">{{ __('storefront.admin.popupPathHint') }}</span>
                    @error('popup_cta_path')<span class="field__error">{{ $message }}</span>@enderror
                </label>

                <label class="field">
                    <span class="field__label">{{ __('storefront.admin.popupDelay') }}</span>
                    <input class="input" type="number" name="popup_delay" min="0" max="120" inputmode="numeric"
                           value="{{ old('popup_delay', $settings->int('popup.delay', 6)) }}">
                </label>

                <label class="field">
                    <span class="field__label">{{ __('storefront.admin.popupSnooze') }}</span>
                    <input class="input" type="number" name="popup_snooze_days" min="0" max="365" inputmode="numeric"
                           value="{{ old('popup_snooze_days', $settings->int('popup.snooze_days', 7)) }}">
                </label>
            </div>
        </section>

        {{-- --------------------------------------------- install prompt --}}
        <section class="admin-card">
            <h2 class="admin-card__title">{{ __('storefront.admin.installSection') }}</h2>
            <p class="admin-card__hint">{{ __('storefront.admin.installHint') }}</p>

            <label class="admin-toggle">
                <input type="checkbox" name="install_enabled" value="1"
                       @checked($settings->bool('install.enabled'))>
                <span>{{ __('storefront.admin.installEnabled') }}</span>
            </label>

            <label class="field" style="max-inline-size:220px">
                <span class="field__label">{{ __('storefront.admin.installDelay') }}</span>
                <input class="input" type="number" name="install_delay" min="0" max="300" inputmode="numeric"
                       value="{{ old('install_delay', $settings->int('install.delay', 12)) }}">
            </label>
        </section>

        <div class="admin-save">
            <button class="btn btn--lg btn--block" type="submit">{{ __('storefront.admin.save') }}</button>
        </div>
    </form>

    {{-- ------------------------------------------------------- cache --}}
    <section class="admin-card">
        <h2 class="admin-card__title">{{ __('storefront.admin.clearCache') }}</h2>
        <p class="admin-card__hint">{{ __('storefront.admin.clearCacheHint') }}</p>

        <form method="POST" action="/admin/cache">
            @csrf
            <button class="btn btn--ghost" type="submit">{{ __('storefront.admin.clearCache') }}</button>
        </form>
    </section>
</main>
@endsection
