@props(['paginator'])

@if ($paginator->hasPages())
    @php $window = collect(range(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2))); @endphp
    <nav class="pager" aria-label="{{ __('panel.common.pagination') }}">
        <span>{{ __('panel.common.showing', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</span>
        <span class="pager__links">
            <a class="pager__link {{ $paginator->onFirstPage() ? 'is-off' : '' }}" href="{{ $paginator->previousPageUrl() ?? '#' }}" rel="prev" aria-label="{{ __('panel.common.previous') }}">‹</a>
            @foreach ($window as $page)
                <a class="pager__link {{ $page === $paginator->currentPage() ? 'is-current' : '' }}" href="{{ $paginator->url($page) }}">{{ $page }}</a>
            @endforeach
            <a class="pager__link {{ $paginator->hasMorePages() ? '' : 'is-off' }}" href="{{ $paginator->nextPageUrl() ?? '#' }}" rel="next" aria-label="{{ __('panel.common.next') }}">›</a>
        </span>
    </nav>
@elseif ($paginator->total() > 0)
    <div class="pager"><span>{{ __('panel.common.total', ['total' => $paginator->total()]) }}</span></div>
@endif
