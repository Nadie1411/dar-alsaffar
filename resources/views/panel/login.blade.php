<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('panel.login.title') }} — {{ __('panel.app') }}</title>
    <link rel="icon" href="{{ asset('favicon.png') }}" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/tokens.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Asset::url('assets/css/panel.css') }}">
</head>
<body class="panel">
<main class="login">
    <form class="login__card" method="POST" action="{{ route('panel.login.store') }}" novalidate>
        @csrf

        <div class="login__brand">
            <img src="{{ asset(config('brand.logo.mark_emerald')) }}" alt="">
            <h1>{{ config('brand.name.'.$locale, config('brand.name.ar')) }}</h1>
            <p class="muted">{{ __('panel.login.sub') }}</p>
        </div>

        <div class="field-p">
            <label for="email">{{ __('panel.login.email') }}</label>
            <input class="in in--ltr" id="email" name="email" type="email" value="{{ old('email') }}"
                   autocomplete="username" autofocus required
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<span class="field-p__error" id="email-error">{{ $message }}</span>@enderror
        </div>

        <div class="field-p">
            <label for="password">{{ __('panel.login.password') }}</label>
            <input class="in in--ltr" id="password" name="password" type="password" autocomplete="current-password" required>
            @error('password')<span class="field-p__error">{{ $message }}</span>@enderror
        </div>

        <label class="check">
            <input type="checkbox" name="remember" value="1">
            <span>{{ __('panel.login.remember') }}</span>
        </label>

        <button class="btn-p btn-p--block" type="submit">{{ __('panel.login.submit') }}</button>

        <div class="row row--between small">
            <a class="link-p" href="{{ url('/') }}">{{ __('panel.login.backToStore') }}</a>
            <a class="link-p" href="{{ route('panel.language', $locale === 'ar' ? 'en' : 'ar') }}">{{ __('panel.top.language') }}</a>
        </div>
    </form>
</main>
</body>
</html>
