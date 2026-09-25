@extends('layouts.app')
@section('title', __('storefront.auth.login'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="auth">
    <div class="auth__form">
        <div class="auth__form-inner">
            <h1 style="font-size:var(--step-title)">{{ __('storefront.auth.login') }}</h1>
            <p class="muted" style="margin-block:var(--space-2) var(--space-6)">{{ __('storefront.auth.loginLede') }}</p>

            @if (session('status'))
                <p class="alert alert--notice" role="status">
                    <x-icon name="info" size="16" class="alert__icon"/> {{ session('status') }}
                </p>
            @endif

            <form method="POST" action="{{ Nav::url('login') }}" class="stack" style="--flow:var(--space-4)" novalidate>
                @csrf

                <label class="field">
                    <span class="field__label">{{ __('storefront.auth.email') }}</span>
                    <input class="input" type="email" name="email" required autocomplete="email" dir="ltr"
                           value="{{ old('email') }}"
                           @error('email') aria-invalid="true" aria-describedby="err-email" @enderror>
                    @error('email')<span class="field__error" id="err-email">{{ $message }}</span>@enderror
                </label>

                <label class="field">
                    <span class="field__label">{{ __('storefront.auth.password') }}</span>
                    <input class="input" type="password" name="password" required autocomplete="current-password" dir="ltr"
                           @error('password') aria-invalid="true" @enderror>
                    @error('password')<span class="field__error">{{ $message }}</span>@enderror
                </label>

                <p style="text-align:start">
                    <a class="link-underline" href="{{ Nav::url('forgot-password') }}">
                        {{ __('storefront.auth.forgot') }}
                    </a>
                </p>

                <button class="btn btn--lg btn--block" type="submit">{{ __('storefront.auth.login') }}</button>
            </form>

            <p class="center muted" style="margin-block-start:var(--space-6);font-size:var(--step-small)">
                {{ __('storefront.auth.noAccount') }}
                <a class="link-underline" href="{{ Nav::url('register') }}">{{ __('storefront.auth.register') }}</a>
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
