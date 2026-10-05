@props(['name', 'size' => 20])

@php
    // 24x24 line icons, drawn for this panel.
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'orders' => '<path d="M8 3h8l1.5 2H20v16H4V5h2.5z"/><path d="M8.5 11h7M8.5 15h7"/>',
        'products' => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="m3 7.5 9 4.5 9-4.5M12 12v9"/>',
        'categories' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>',
        'customers' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.5-3.5 3-5.5 6.5-5.5s6 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14.8c1.8.6 3 2.3 3.2 5.2"/>',
        'inbox' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'addons' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13"/><path d="M12 8c-2.5 0-4-1-4-2.5S9.5 3 12 8c2.5-5 4-3.5 4-2.5S14.500 8 12 8z"/>',
        'vouchers' => '<path d="M3 9a2 2 0 0 0 0 6v3h18v-3a2 2 0 0 1 0-6V6H3z"/><path d="m9 15 6-6"/><circle cx="9.5" cy="9.5" r=".6"/><circle cx="14.5" cy="14.5" r=".6"/>',
        'delivery' => '<path d="M2 6h11v10H2zM13 9h4l4 3v4h-8"/><circle cx="6.5" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'payments' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/>',
        'content' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
        'reports' => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
        'staff' => '<path d="M12 3l8 3v6c0 4.500-3.200 7.800-8 9-4.800-1.200-8-4.500-8-9V6z"/><circle cx="12" cy="10" r="2.500"/><path d="M7.500 17c.8-2 2.500-3 4.500-3s3.700 1 4.500 3"/>',
        'log' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bell' => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>',
        'bag' => '<path d="M5 8h14l-1 12H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'logout' => '<path d="M9 4H5v16h4M15 8l4 4-4 4M19 12H9"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="6.500"/><path d="m16 16 4.500 4.500"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.500 6.500 4 4"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'check' => '<path d="m5 12.500 4.500 4.500L19 7"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'print' => '<path d="M7 9V3h10v6M7 17H4v-7h16v7h-3"/><rect x="7" y="14" width="10" height="7"/>',
        'download' => '<path d="M12 4v11M7 11l5 5 5-5M4 20h16"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'alert' => '<path d="M12 4 2.500 20h19z"/><path d="M12 10v4M12 17h.01"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.800"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
        'phone' => '<path d="M5 4h4l2 5-2.500 1.500a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
        'more' => '<circle cx="5" cy="12" r="1.500"/><circle cx="12" cy="12" r="1.500"/><circle cx="19" cy="12" r="1.500"/>',
        'back' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'settings' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'refresh' => '<path d="M20 11a8 8 0 1 0-2.300 5.700M20 5v6h-6"/>',
        'truck' => '<path d="M2 6h11v10H2zM13 9h4l4 3v4h-8"/><circle cx="6.500" cy="17.500" r="1.800"/><circle cx="17" cy="17.500" r="1.800"/>',
        'pin' => '<path d="M12 21s7-6.300 7-12a7 7 0 0 0-14 0c0 5.700 7 12 7 12z"/><circle cx="12" cy="9" r="2.500"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><rect x="3" y="7" width="18" height="13" rx="2"/><circle cx="16.500" cy="13.500" r="1.200"/>',
    ];
@endphp

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="{{ $size }}" height="{{ $size }}" fill="none"
     stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
     {{ $attributes }}>{!! $paths[$name] ?? '' !!}</svg>
