@props(['label', 'value', 'unit' => null, 'note' => null, 'icon' => null, 'href' => null, 'alert' => false])

@php
    $classes = ['card', 'stat', 'stat--alert' => $alert];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
@else
    <div {{ $attributes->class($classes) }}>
@endif
    <span class="stat__label">@if ($icon)<x-panel.icon :name="$icon" :size="18"/>@endif {{ $label }}</span>
    <span class="stat__value">{{ $value }}@if ($unit)<small>{{ $unit }}</small>@endif</span>
    @if ($note)<span class="stat__note">{{ $note }}</span>@endif
@if ($href)
    </a>
@else
    </div>
@endif
