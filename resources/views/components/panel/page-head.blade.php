@props(['title', 'sub' => null, 'back' => null, 'backLabel' => null])

<div class="page-head">
    <div>
        @if ($back)
            <div class="crumbs"><a href="{{ $back }}">{{ $backLabel ?? __('panel.common.back') }}</a></div>
        @endif
        <h1>{{ $title }}</h1>
        @if ($sub)<p class="page-head__sub">{{ $sub }}</p>@endif
    </div>
    @if (! $slot->isEmpty())
        <div class="page-head__actions">{{ $slot }}</div>
    @endif
</div>
