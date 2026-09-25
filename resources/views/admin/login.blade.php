@extends('admin._layout')
@section('title', __('storefront.admin.signIn'))

@section('body')
<main class="admin-auth">
    <form class="admin-auth__card" method="POST" action="/admin/login">
        @csrf

        <img src="{{ asset(config('brand.logo.mark_emerald')) }}" alt="" width="224" height="290"
             class="admin-auth__logo">

        <h1 class="admin-auth__title">{{ __('storefront.admin.title') }}</h1>
        <p class="muted" style="margin-block-end:var(--space-5);font-size:var(--step-small)">
            {{ __('storefront.admin.subtitle') }}
        </p>

        <label class="field" style="text-align:start">
            <span class="field__label">{{ __('storefront.admin.password') }}</span>
            <input class="input" type="password" name="password" required autofocus
                   autocomplete="current-password" dir="ltr"
                   @error('password') aria-invalid="true" aria-describedby="err-pw" @enderror>
            @error('password')
                <span class="field__error" id="err-pw">{{ $message }}</span>
            @enderror
        </label>

        <button class="btn btn--lg btn--block" type="submit" style="margin-block-start:var(--space-4)">
            {{ __('storefront.admin.signIn') }}
        </button>
    </form>
</main>
@endsection
