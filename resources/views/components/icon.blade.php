@props([
    'name',
    'size' => 20,
])

@php
    // Inline, currentColor-driven icons: no icon font, no extra request, and
    // they inherit whatever colour the surrounding component sets.
    $paths = [
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'heart'    => '<path d="M12 20.5 4.7 13.4a4.6 4.6 0 0 1 0-6.6 4.8 4.8 0 0 1 6.7 0l.6.6.6-.6a4.8 4.8 0 0 1 6.7 0 4.6 4.6 0 0 1 0 6.6Z"/>',
        'bag'      => '<path d="M6 8h12l1 12H5Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'close'    => '<path d="m6 6 12 12M18 6 6 18"/>',
        'chevron'  => '<path d="m6 9 6 6 6-6"/>',
        'arrow'    => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'arrow-start' => '<path d="M19 12H5"/><path d="m11 6-6 6 6 6"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'minus'    => '<path d="M5 12h14"/>',
        'trash'    => '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/>',
        'check'    => '<path d="m4 12.5 5 5L20 6.5"/>',
        'star'     => '<path d="m12 3.5 2.6 5.6 6 .8-4.4 4.3 1.1 6.1-5.3-3-5.3 3 1.1-6.1L3.4 9.9l6-.8Z"/>',
        'filter'   => '<path d="M3 6h18"/><path d="M7 12h10"/><path d="M11 18h2"/>',
        'sort'     => '<path d="M7 4v16"/><path d="m3 8 4-4 4 4"/><path d="M17 20V4"/><path d="m13 16 4 4 4-4"/>',
        'share'    => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.6 6.8-4.2M8.6 13.4l6.8 4.2"/>',
        'zoom'     => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M11 8v6M8 11h6"/>',
        'truck'    => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
        'shield'   => '<path d="M12 3.5 5 6v5.5c0 4.3 3 7.6 7 9 4-1.4 7-4.7 7-9V6Z"/><path d="m9 12 2 2 4-4"/>',
        'sparkle'  => '<path d="M12 3.5 13.8 9l5.7 1.8L13.8 13 12 18.5 10.2 13 4.5 10.8 10.2 9Z"/>',
        'headset'  => '<path d="M4 13a8 8 0 0 1 16 0"/><path d="M4 13v3a2 2 0 0 0 2 2h1v-5H6a2 2 0 0 0-2 2Z"/><path d="M20 13v3a2 2 0 0 1-2 2h-1v-5h1a2 2 0 0 1 2 2Z"/>',
        'phone'    => '<path d="M6 3h3l2 5-2.5 1.5a12 12 0 0 0 5 5L15 12l5 2v3a2 2 0 0 1-2.2 2A16 16 0 0 1 4 5.2 2 2 0 0 1 6 3Z"/>',
        'mail'     => '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/>',
        'pin'      => '<path d="M12 21s7-5.7 7-11a7 7 0 1 0-14 0c0 5.3 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        'package'  => '<path d="M12 3 3 7.5v9L12 21l9-4.5v-9Z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
        'gift'     => '<path d="M4 10h16v10H4z"/><path d="M4 10V7h16v3"/><path d="M12 7v13"/><path d="M12 7c-2.5 0-4-1-4-2.2C8 3.8 8.8 3 9.8 3 11.2 3 12 5 12 7Zm0 0c2.5 0 4-1 4-2.2 0-1-.8-1.8-1.8-1.8C12.8 3 12 5 12 7Z"/>',
        'grid'     => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'eye'      => '<path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.8"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
        'volume'   => '<path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/>',
        'mute'     => '<path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="m17 9 4 6"/><path d="m21 9-4 6"/>',
        'whatsapp' => '<path d="M12.04 3a8.9 8.9 0 0 0-7.6 13.53L3.5 21l4.6-1.2A8.9 8.9 0 1 0 12.04 3Z"/><path d="M8.9 8.3c.2-.5.4-.5.6-.5h.5c.2 0 .4 0 .6.5l.7 1.6c.1.3 0 .5-.1.7l-.4.5c-.1.2-.2.3 0 .6a6.4 6.4 0 0 0 3 2.6c.3.1.5.1.6-.1l.6-.7c.2-.2.3-.2.6-.1l1.6.8c.3.1.4.2.4.4a1.9 1.9 0 0 1-1.3 1.6c-.6.2-1.4.2-3.4-.7a9 9 0 0 1-3.9-3.7c-.8-1.5-.6-2.4-.1-3.5Z"/>',
        'instagram'=> '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17 7h.01"/>',
        'card'     => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/><path d="M6 15h4"/>',
        'lock'     => '<rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/><path d="M12 15v2"/>',
        'bank'     => '<path d="M3 9.5 12 4l9 5.5"/><path d="M5 10v7M9.5 10v7M14.5 10v7M19 10v7"/><path d="M3 20h18"/>',
        'contactless' => '<path d="M8 8.5a5 5 0 0 1 0 7"/><path d="M11.5 6a8.5 8.5 0 0 1 0 12"/><path d="M15 3.5a12 12 0 0 1 0 17"/>',
        'cash'     => '<rect x="2.5" y="6.5" width="19" height="11" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/>',
        'tiktok'   => '<path d="M14 4v10.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 4c.4 2.2 2 3.8 4.5 4"/>',
    ];

    $path = $paths[$name] ?? $paths['info'];
    $filled = in_array($name, ['star'], true);
@endphp

<svg {{ $attributes->merge([
        'width' => $size,
        'height' => $size,
        'viewBox' => '0 0 24 24',
        'fill' => $filled ? 'currentColor' : 'none',
        'stroke' => 'currentColor',
        'stroke-width' => $filled ? '0' : '1.4',
        'stroke-linecap' => 'round',
        'stroke-linejoin' => 'round',
        'aria-hidden' => 'true',
        'focusable' => 'false',
    ]) }}>{!! $path !!}</svg>
