@extends('layouts.app')

@section('title', $pageTitle)

@php use App\Support\Nav; @endphp

@section('content')
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="crumbs">
            <li><a href="{{ Nav::url() }}">{{ __('storefront.nav.home') }}</a></li>
            <li><span aria-current="page">{{ $pageTitle }}</span></li>
        </ol>
    </nav>

    <div class="container--narrow" style="padding-inline:0">
        <div class="page-head">
            <h1 class="page-head__title">{{ $pageTitle }}</h1>
        </div>

        {{-- These policies are legal statements and belong to the business.
             The page is styled and ready; the wording comes from the client,
             not from us. --}}
        <p class="placeholder-note">
            <x-icon name="info" size="18"/>
            <span>{{ __('storefront.content.contentPending') }}</span>
        </p>

        <div class="prose" style="padding-block-end:var(--space-8)">
            @yield('policy')
        </div>
    </div>
</div>
@endsection
