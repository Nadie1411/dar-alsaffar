@props(['status'])

@php
    // Order statuses and payment statuses share one set of colours.
    $tone = match ($status->value) {
        'new', 'accepted' => 'blue',
        'preparing', 'out_for_delivery', 'refunded' => 'amber',
        'delivered', 'paid' => 'green',
        'cancelled', 'failed' => 'red',
        default => 'grey',
    };
@endphp

<x-panel.pill :tone="$tone" {{ $attributes }}>{{ method_exists($status, 'panelLabel') ? $status->panelLabel() : $status->label() }}</x-panel.pill>
