@extends('panel.layout')

@section('title', __('panel.modules.content'))

@section('content')
    @php
        $value = fn (string $key, $default = '') => old(str_replace('.', '_', $key), $settings->get($key, $default));
        $flag = fn (string $key, bool $default = true) => old(str_replace('.', '_', $key), $settings->bool($key, $default));
    @endphp

    <x-panel.page-head :title="__('panel.modules.content')" :sub="__('panel.content.sub')"/>

    <form class="stack" method="POST" enctype="multipart/form-data" action="{{ route('panel.content.update') }}">
        @csrf @method('PUT')

        {{-- ---- offer strip ----------------------------------------------- --}}
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.content.strip') }}</h2></div>
            <div class="card__body stack" style="--gap:12px">
                <p class="muted small">{{ __('panel.content.stripHint') }}</p>
                <x-panel.switch name="strip_enabled" :label="__('panel.content.stripEnabled')" :checked="$flag('strip.enabled')"/>
            </div>
        </section>

        {{-- ---- pop-up ------------------------------------------------------ --}}
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.content.popup') }}</h2></div>
            <div class="card__body stack" style="--gap:16px">
                <p class="muted small">{{ __('panel.content.popupHint') }}</p>
                <x-panel.switch name="popup_enabled" :label="__('panel.content.popupEnabled')" :checked="$flag('popup.enabled')"/>
                <div class="form-grid">
                    <x-panel.input name="popup_title" :label="__('panel.content.popupTitle')" :value="$value('popup.title')" maxlength="120"/>
                    <x-panel.input name="popup_cta_label" :label="__('panel.content.popupCta')" :value="$value('popup.cta_label')" maxlength="60"/>
                    <div class="span-2"><x-panel.textarea name="popup_body" :label="__('panel.content.popupBody')" :value="$value('popup.body')" rows="3" maxlength="400"/></div>
                    <x-panel.input name="popup_cta_path" :label="__('panel.content.popupPath')" :value="$value('popup.cta_path')" maxlength="80" ltr :hint="__('panel.content.popupPathHint')"/>
                    <span></span>
                    <x-panel.input name="popup_delay" type="number" min="0" max="120" :label="__('panel.content.popupDelay')" :value="$value('popup.delay', 6)" ltr/>
                    <x-panel.input name="popup_snooze_days" type="number" min="0" max="365" :label="__('panel.content.popupSnooze')" :value="$value('popup.snooze_days', 7)" ltr/>
                </div>
                <x-panel.image-field name="popup_image" :label="__('panel.content.popupImage')" :current="$settings->get('popup.image')" removeName="remove_image" :hint="__('panel.content.popupImageHint')"/>
            </div>
        </section>

        {{-- ---- install prompt ---------------------------------------------- --}}
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.content.install') }}</h2></div>
            <div class="card__body stack" style="--gap:12px">
                <p class="muted small">{{ __('panel.content.installHint') }}</p>
                <x-panel.switch name="install_enabled" :label="__('panel.content.installEnabled')" :checked="$flag('install.enabled')"/>
                <div style="max-inline-size:240px"><x-panel.input name="install_delay" type="number" min="0" max="300" :label="__('panel.content.installDelay')" :value="$value('install.delay', 12)" ltr/></div>
            </div>
        </section>

        {{-- ---- home page media --------------------------------------------- --}}
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.content.hero') }}</h2></div>
            <div class="card__body stack" style="--gap:16px">
                <p class="muted small">{{ __('panel.content.heroHint') }}</p>
                <x-panel.image-field name="hero_image" :label="__('panel.content.heroImage')" :current="$settings->get('hero.image')" removeName="remove_hero_image" :hint="__('panel.content.heroImageHint')"/>
                <div class="field-p">
                    <span class="field-p__label">{{ __('panel.content.heroVideo') }}</span>
                    @if ($video = \App\Support\Media::url($settings->get('hero.video')))
                        <video src="{{ $video }}" controls preload="metadata" style="max-inline-size:360px;border-radius:8px"></video>
                        <label class="check"><input type="checkbox" name="remove_hero_video" value="1"> {{ __('panel.content.removeVideo') }}</label>
                    @endif
                    <input class="in" type="file" name="hero_video" accept="video/mp4">
                    @error('hero_video')<span class="field-p__error">{{ $message }}</span>@else<span class="field-p__hint">{{ __('panel.content.heroVideoHint') }}</span>@enderror
                </div>
            </div>
        </section>

        {{-- ---- about page --------------------------------------------------- --}}
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.content.about') }}</h2></div>
            <div class="card__body stack" style="--gap:16px">
                <p class="muted small">{{ __('panel.content.aboutHint') }}</p>
                @foreach (['story', 'philosophy', 'quality'] as $section)
                    <div class="form-grid">
                        <x-panel.textarea :name="'about_'.$section.'_ar'" :label="__('panel.content.'.$section).' — '.__('panel.common.arabic')" :value="$value('about.'.$section.'_ar')" rows="4" maxlength="2000" lang="ar" dir="rtl"/>
                        <x-panel.textarea :name="'about_'.$section.'_en'" :label="__('panel.content.'.$section).' — '.__('panel.common.english')" :value="$value('about.'.$section.'_en')" rows="4" maxlength="2000" lang="en" dir="ltr"/>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ---- contact ------------------------------------------------------ --}}
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.content.contact') }}</h2></div>
            <div class="card__body stack" style="--gap:16px">
                <p class="muted small">{{ __('panel.content.contactHint') }}</p>
                <div class="form-grid">
                    <x-panel.input name="contact_phone" :label="__('panel.content.contactPhone')" :value="$value('contact.phone')" maxlength="20" ltr/>
                    <x-panel.input name="contact_whatsapp" :label="__('panel.content.contactWhatsapp')" :value="$value('contact.whatsapp')" maxlength="20" ltr :hint="__('panel.content.contactWhatsappHint')"/>
                    <x-panel.input name="contact_email" type="email" :label="__('panel.orders.email')" :value="$value('contact.email')" maxlength="190" ltr/>
                    <span></span>
                    <x-panel.input name="social_instagram" type="url" :label="__('panel.content.socialInstagram')" :value="$value('social.instagram')" maxlength="200" ltr/>
                    <x-panel.input name="social_tiktok" type="url" :label="__('panel.content.socialTiktok')" :value="$value('social.tiktok')" maxlength="200" ltr/>
                </div>
            </div>
        </section>

        {{-- ---- order alerts -------------------------------------------------- --}}
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.content.alerts') }}</h2></div>
            <div class="card__body stack" style="--gap:12px">
                <p class="muted small">{{ __('panel.content.alertsHint') }}</p>
                <x-panel.switch name="orders_alert" :label="__('panel.content.alertsOn')" :checked="$flag('orders.alert')"/>
                <div style="max-inline-size:240px"><x-panel.input name="orders_poll" type="number" min="10" max="600" :label="__('panel.content.alertsPoll')" :value="$value('orders.poll', 30)" ltr/></div>
                <div style="max-inline-size:420px"><x-panel.input name="orders_notify_email" type="email" :label="__('panel.content.notifyEmail')" :value="$value('orders.notify_email')" maxlength="190" ltr :hint="__('panel.content.notifyEmailHint')"/></div>
            </div>
        </section>

        <div><button class="btn-p" type="submit">{{ __('panel.common.saveChanges') }}</button></div>
    </form>
@endsection
