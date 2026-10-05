@extends('layouts.app')
@section('title', __('storefront.auth.resetTitle'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="container container--narrow section">
    <div style="max-inline-size:min(420px,100%);margin-inline:auto">
        <h1 style="font-size:var(--step-title)">{{ __('storefront.auth.resetTitle') }}</h1>
        <p class="muted" style="margin-block:var(--space-2) var(--space-6)">{{ __('storefront.auth.resetLede') }}</p>

        <form method="POST" action="{{ Nav::url('reset-password') }}" class="stack" style="--flow:var(--space-4)">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label class="field">
                <span class="field__label">{{ __('storefront.auth.email') }}</span>
                <input class="input" type="email" name="email" required autocomplete="email" dir="ltr"
                       value="{{ old('email', $email) }}" @error('email') aria-invalid="true" @enderror>
                @error('email')<span class="field__error">{{ $message }}</span>@enderror
            </label>

            <label class="field">
                <span class="field__label">{{ __('storefront.auth.newPassword') }}</span>
                <input class="input" type="password" name="password" required minlength="8" maxlength="64"
                       autocomplete="new-password" dir="ltr" @error('password') aria-invalid="true" @enderror>
                <span class="field__hint">{{ __('storefront.auth.passwordHint') }}</span>
                @error('password')<span class="field__error">{{ $message }}</span>@enderror
            </label>

            <label class="field">
                <span class="field__label">{{ __('storefront.auth.confirmPassword') }}</span>
                <input class="input" type="password" name="password_confirmation" required minlength="8" maxlength="64"
                       autocomplete="new-password" dir="ltr">
            </label>

            <button class="btn btn--lg btn--block" type="submit">{{ __('storefront.auth.resetButton') }}</button>
        </form>
    </div>
</div>
@endsection
