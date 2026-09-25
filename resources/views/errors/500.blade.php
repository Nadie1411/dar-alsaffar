@extends('layouts.app')
@section('title', __('storefront.errors.500'))

@php use App\Support\Nav; @endphp

@section('content')
<div class="container container--narrow section center">
    <p class="section-heading__eyebrow" style="justify-content:center">500</p>
    <h1 style="font-size:var(--step-title);margin-block-end:var(--space-3)">{{ __('storefront.errors.500') }}</h1>
    <p class="muted" style="max-inline-size:44ch;margin-inline:auto">{{ __('storefront.errors.500Text') }}</p>
    <p style="margin-block-start:var(--space-6)">
        <a class="btn" href="{{ Nav::url() }}">{{ __('storefront.errors.backHome') }}</a>
    </p>
</div>
@endsection
