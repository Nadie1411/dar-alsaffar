@extends('layouts.app')
@section('title', __('storefront.account.settings'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="container">
    <div class="page-head"><h1 class="page-head__title">{{ __('storefront.account.settings') }}</h1></div>

    <div class="account" style="padding-block-end:var(--space-8)">
        @include('partials.account-nav')

        <div class="panel">
            @if (session('status'))
                <p class="alert alert--success" role="status">
                    <x-icon name="check" size="16" class="alert__icon"/> {{ session('status') }}
                </p>
            @endif

            <form method="POST" action="{{ Nav::url('account/settings') }}" class="stack" style="--flow:var(--space-4)">
                @csrf @method('PATCH')

                <label class="field">
                    <span class="field__label">{{ __('storefront.auth.fullName') }}</span>
                    <input class="input" type="text" name="fullName" required
                           value="{{ old('fullName', $customer['fullName'] ?? '') }}"
                           @error('fullName') aria-invalid="true" @enderror>
                    @error('fullName')<span class="field__error">{{ $message }}</span>@enderror
                </label>

                {{-- Email and phone identify the account upstream and are
                     changed through customer support, not here. --}}
                <label class="field">
                    <span class="field__label">{{ __('storefront.auth.email') }}</span>
                    <input class="input" type="email" dir="ltr" value="{{ $customer['email'] ?? '' }}" disabled>
                </label>

                <label class="field">
                    <span class="field__label">{{ __('storefront.auth.phone') }}</span>
                    <input class="input" type="tel" dir="ltr" value="{{ $customer['phoneNumber'] ?? '' }}" disabled>
                </label>

                <button class="btn" type="submit">{{ __('storefront.actions.save') }}</button>
            </form>
        </div>
    </div>
</div>
@endsection
