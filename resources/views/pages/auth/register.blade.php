@extends('layouts.app')
@section('title', __('storefront.auth.register'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="auth">
    <div class="auth__form">
        <div class="auth__form-inner">
            <h1 style="font-size:var(--step-title)">{{ __('storefront.auth.register') }}</h1>
            <p class="muted" style="margin-block:var(--space-2) var(--space-6)">{{ __('storefront.auth.registerLede') }}</p>

            <form method="POST" action="{{ Nav::url('register') }}" class="stack" style="--flow:var(--space-4)" novalidate>
                @csrf

                <label class="field">
                    <span class="field__label">{{ __('storefront.auth.fullName') }}</span>
                    <input class="input" type="text" name="fullName" required autocomplete="name"
                           value="{{ old('fullName') }}" @error('fullName') aria-invalid="true" @enderror>
                    @error('fullName')<span class="field__error">{{ $message }}</span>@enderror
                </label>

                <label class="field">
                    <span class="field__label">{{ __('storefront.auth.email') }}</span>
                    <input class="input" type="email" name="email" required autocomplete="email" dir="ltr"
                           value="{{ old('email') }}" @error('email') aria-invalid="true" @enderror>
                    @error('email')<span class="field__error">{{ $message }}</span>@enderror
                </label>

                <div class="field">
                    <span class="field__label">{{ __('storefront.auth.phone') }}</span>
                    <div class="phone-field">
                        <span class="phone-field__prefix" aria-hidden="true">+{{ config('brand.country.dial') }}</span>
                        <input class="input" type="tel" name="phone" required dir="ltr" inputmode="numeric"
                               maxlength="{{ config('brand.country.phone_len') }}" autocomplete="tel-national"
                               value="{{ old('phone') }}" aria-describedby="hint-phone"
                               @error('phone') aria-invalid="true" @enderror>
                    </div>
                    <span class="field__hint" id="hint-phone">{{ __('storefront.checkout.phoneHint') }}</span>
                    @error('phone')<span class="field__error">{{ $message }}</span>@enderror
                </div>

                <label class="field">
                    <span class="field__label">{{ __('storefront.auth.password') }}</span>
                    <input class="input" type="password" name="password" required minlength="8"
                           autocomplete="new-password" dir="ltr" aria-describedby="hint-pw"
                           @error('password') aria-invalid="true" @enderror>
                    <span class="field__hint" id="hint-pw">{{ __('storefront.auth.passwordHint') }}</span>
                    @error('password')<span class="field__error">{{ $message }}</span>@enderror
                </label>

                <button class="btn btn--lg btn--block" type="submit">{{ __('storefront.auth.register') }}</button>
            </form>

            <p class="center muted" style="margin-block-start:var(--space-6);font-size:var(--step-small)">
                {{ __('storefront.auth.hasAccount') }}
                <a class="link-underline" href="{{ Nav::url('login') }}">{{ __('storefront.auth.login') }}</a>
            </p>
        </div>
    </div>

    <div class="auth__aside">
        <img src="{{ asset('assets/brand/logo-green.jpg') }}" alt="" loading="lazy">
        <div class="auth__aside-body">
            <p class="section-heading__eyebrow" style="color:var(--gold-300)">{{ __('storefront.brand.name') }}</p>
            <p style="font-family:var(--font-display);font-size:var(--step-title)">{{ __('storefront.auth.asideTitle') }}</p>
            <p style="color:rgba(243,232,200,.75);font-size:var(--step-small)">{{ __('storefront.auth.asideText') }}</p>
        </div>
    </div>
</div>
@endsection
