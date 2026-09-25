@if ($paginator->hasPages())
    {{-- Arrows point the way the script runs, so "next" is on the left in
         Arabic and on the right in English. --}}
    @php
        $prevIcon = ($dir ?? 'rtl') === 'rtl' ? 'arrow' : 'arrow-start';
        $nextIcon = ($dir ?? 'rtl') === 'rtl' ? 'arrow-start' : 'arrow';
    @endphp

    <ul style="display:flex;align-items:center;justify-content:center;gap:var(--space-2);flex-wrap:wrap">
        <li>
            @if ($paginator->onFirstPage())
                <span class="icon-btn" aria-disabled="true" style="opacity:.35">
                    <x-icon :name="$prevIcon" size="18"/>
                </span>
            @else
                <a class="icon-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   aria-label="{{ __('storefront.actions.back') }}">
                    <x-icon :name="$prevIcon" size="18"/>
                </a>
            @endif
        </li>

        @foreach ($elements as $element)
            @if (is_string($element))
                <li><span class="muted" style="padding-inline:var(--space-2)">{{ $element }}</span></li>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    <li>
                        @if ($page == $paginator->currentPage())
                            <span class="icon-btn" aria-current="page"
                                  style="background:var(--ink-900);color:var(--cream-400);font-variant-numeric:tabular-nums">
                                {{ $page }}
                            </span>
                        @else
                            <a class="icon-btn" href="{{ $url }}"
                               style="font-variant-numeric:tabular-nums">{{ $page }}</a>
                        @endif
                    </li>
                @endforeach
            @endif
        @endforeach

        <li>
            @if ($paginator->hasMorePages())
                <a class="icon-btn" href="{{ $paginator->nextPageUrl() }}" rel="next"
                   aria-label="{{ __('storefront.actions.continue') }}">
                    <x-icon :name="$nextIcon" size="18"/>
                </a>
            @else
                <span class="icon-btn" aria-disabled="true" style="opacity:.35">
                    <x-icon :name="$nextIcon" size="18"/>
                </span>
            @endif
        </li>
    </ul>
@endif
