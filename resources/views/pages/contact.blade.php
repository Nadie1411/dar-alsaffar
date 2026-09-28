@extends('layouts.app')

@section('title', __('storefront.content.contactTitle'))
@section('description', __('storefront.content.contactLede'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="container">
    <div class="page-head">
        <h1 class="page-head__title">{{ __('storefront.content.contactTitle') }}</h1>
        <p class="page-head__lede">{{ __('storefront.content.contactLede') }}</p>
    </div>

    <div class="split" style="padding-block-end:var(--space-8);align-items:start">
        <div>
            @if (session('status'))
                <p class="alert alert--success" role="status">
                    <x-icon name="check" size="16" class="alert__icon"/> {{ session('status') }}
                </p>
            @endif

            <form method="POST" action="{{ Nav::url('contact-us') }}" class="stack" style="--flow:var(--space-4)" novalidate>
                @csrf

                <div class="grid-2">
                    <label class="field">
                        <span class="field__label">{{ __('storefront.content.name') }}</span>
                        <input class="input" type="text" name="name" required autocomplete="name"
                               value="{{ old('name') }}" @error('name') aria-invalid="true" @enderror>
                        @error('name')<span class="field__error">{{ $message }}</span>@enderror
                    </label>

                    <label class="field">
                        <span class="field__label">{{ __('storefront.auth.email') }}</span>
                        <input class="input" type="email" name="email" required autocomplete="email" dir="ltr"
                               value="{{ old('email') }}" @error('email') aria-invalid="true" @enderror>
                        @error('email')<span class="field__error">{{ $message }}</span>@enderror
                    </label>
                </div>

                <div class="field">
                    <span class="field__label">{{ __('storefront.auth.phone') }}</span>
                    <div class="phone-field">
                        <span class="phone-field__prefix" aria-hidden="true">+{{ config('brand.country.dial') }}</span>
                        <input class="input" type="tel" name="phone" dir="ltr" inputmode="numeric"
                               maxlength="{{ config('brand.country.phone_len') }}" value="{{ old('phone') }}">
                    </div>
                </div>

                <label class="field">
                    <span class="field__label">{{ __('storefront.content.message') }}</span>
                    <textarea class="textarea" name="message" rows="6" required
                              @error('message') aria-invalid="true" @enderror>{{ old('message') }}</textarea>
                    @error('message')<span class="field__error">{{ $message }}</span>@enderror
                </label>

                <button class="btn btn--lg" type="submit">{{ __('storefront.actions.send') }}</button>
            </form>
        </div>

        <aside class="panel">
            <h2 class="panel__title" style="margin-block-end:var(--space-4)">{{ __('storefront.footer.contact') }}</h2>

            <ul class="footer__contact" style="color:var(--text-secondary)">
                <li>
                    <x-icon name="whatsapp" size="18" style="color:var(--emerald-700)"/>
                    <span>
                        <span class="order-row__label">{{ __('storefront.content.whatsapp') }}</span>
                        <a class="link-underline" href="https://wa.me/{{ $contact['whatsapp'] }}"
                           target="_blank" rel="noopener" dir="ltr">
                            +{{ $contact['whatsapp'] }}
                        </a>
                    </span>
                </li>
                <li>
                    <x-icon name="phone" size="18" style="color:var(--emerald-700)"/>
                    <span>
                        <span class="order-row__label">{{ __('storefront.content.callUs') }}</span>
                        <a class="link-underline" href="tel:{{ $contact['phone'] }}" dir="ltr">
                            {{ $contact['phone'] }}
                        </a>
                    </span>
                </li>
                @if ($contact['email'])
                    <li>
                        <x-icon name="mail" size="18" style="color:var(--emerald-700)"/>
                        <span>
                            <span class="order-row__label">{{ __('storefront.content.emailUs') }}</span>
                            <a class="link-underline" href="mailto:{{ $contact['email'] }}" dir="ltr">
                                {{ $contact['email'] }}
                            </a>
                        </span>
                    </li>
                @endif
                <li>
                    <x-icon name="pin" size="18" style="color:var(--emerald-700)"/>
                    <span>
                        <span class="order-row__label">{{ __('storefront.checkout.address') }}</span>
                        {{ $locale === 'ar' ? config('brand.country.name_ar') : config('brand.country.name_en') }}
                    </span>
                </li>
            </ul>

            <h3 class="footer__title" style="color:var(--gold-600);margin-block:var(--space-6) var(--space-3)">
                {{ __('storefront.content.followUs') }}
            </h3>
            <div class="socials" style="margin:0">
                <a href="{{ $social['instagram'] }}" target="_blank" rel="noopener"
                   aria-label="Instagram" style="border-color:var(--line);color:var(--text-secondary)">
                    <x-icon name="instagram" size="18"/>
                </a>
                <a href="{{ $social['tiktok'] }}" target="_blank" rel="noopener"
                   aria-label="TikTok" style="border-color:var(--line);color:var(--text-secondary)">
                    <x-icon name="tiktok" size="18"/>
                </a>
            </div>
        </aside>
    </div>
</div>
@endsection
