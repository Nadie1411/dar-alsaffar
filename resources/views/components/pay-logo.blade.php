@props(['type', 'height' => 28])

@php
    // The marks MyFatoorah's own payment page shows. A method with no mark here
    // (Google Pay) falls back to a plain card glyph rather than a made-up logo.
    $file = match (true) {
        $type === 'knet' => 'kn.png',
        $type === 'card' => 'vm.png',
        str_starts_with($type, 'apple_pay') => 'ap.png',
        default => null,
    };
@endphp

@if ($file)
    <img src="{{ \App\Support\Asset::url('assets/img/pay/'.$file) }}" alt="" height="{{ $height }}" loading="lazy" decoding="async" {{ $attributes->class('pay-logo') }}>
@else
    <x-icon name="card" :size="$height"/>
@endif
