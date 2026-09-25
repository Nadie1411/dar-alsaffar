@extends('layouts.app')
@section('title', __('storefront.auth.forgotTitle'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="container container--narrow section">
    <div style="max-inline-size:min(420px,100%);margin-inline:auto">
        <h1 style="font-size:var(--step-title)">{{ __('storefront.auth.forgotTitle') }}</h1>
        <p class="muted" style="margin-block:var(--space-2) var(--space-6)">{{ __('storefront.auth.forgotLede') }}</p>

        @if (session('status'))
            <p class="alert alert--success" role="status">
                <x-icon name="check" size="16" class="alert__icon"/> {{ session('status') }}
            </p>
        @endif

        <form method="POST" action="{{ Nav::url('forgot-password') }}" class="stack" style="--flow:var(--space-4)">
            @csrf
            <label class="field">
                <span class="field__label">{{ __('storefront.auth.email') }}</span>
                <input class="input" type="email" name="email" required autocomplete="email" dir="ltr"
                       value="{{ old('email') }}" @error('email') aria-invalid="true" @enderror>
                @error('email')<span class="field__error">{{ $message }}</span>@enderror
            </label>
            <button class="btn btn--lg btn--block" type="submit">{{ __('storefront.actions.send') }}</button>
        </form>

        <p class="center" style="margin-block-start:var(--space-5)">
            <a class="link-underline" href="{{ Nav::url('login') }}">{{ __('storefront.auth.login') }}</a>
        </p>
    </div>
</div>
@endsection
