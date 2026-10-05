@extends('panel.layout')

@section('title', __('panel.modules.orders'))

@section('content')
    @php
        use App\Enums\OrderStatus;

        $query = fn (array $extra = []) => array_filter(array_merge($filters, ['page' => null], $extra), fn ($value) => $value !== '' && $value !== null && $value !== 'all');
    @endphp

    <x-panel.page-head :title="__('panel.modules.orders')">
        <a class="btn-p btn-p--ghost" href="{{ route('panel.orders.export', $query()) }}">
            <x-panel.icon name="download" :size="18"/>{{ __('panel.common.export') }}
        </a>
    </x-panel.page-head>

    <section class="card">
        <nav class="tabs" aria-label="{{ __('panel.common.status') }}">
            <a class="tab {{ $filters['status'] === 'all' ? 'is-active' : '' }}" href="{{ route('panel.orders.index', $query(['status' => 'all'])) }}">
                {{ __('panel.common.all') }} <span class="tab__n">{{ $allCount }}</span>
            </a>
            @foreach ($statuses as $status)
                <a class="tab {{ $filters['status'] === $status->value ? 'is-active' : '' }}" href="{{ route('panel.orders.index', $query(['status' => $status->value])) }}">
                    {{ $status->panelLabel() }} <span class="tab__n">{{ $counts[$status->value] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        <form class="toolbar" method="GET" action="{{ route('panel.orders.index') }}">
            @if ($filters['status'] !== 'all')<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <div class="search">
                <x-panel.icon name="search" :size="18"/>
                <input class="in" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('panel.orders.searchHint') }}" aria-label="{{ __('panel.common.search') }}">
            </div>
            <select class="sel" name="payment" aria-label="{{ __('panel.orders.payment') }}" data-autosubmit>
                <option value="">{{ __('panel.orders.anyPayment') }}</option>
                <option value="cod" @selected($filters['payment'] === 'cod')>{{ __('panel.orders.methods.cod') }}</option>
                <option value="online" @selected($filters['payment'] === 'online')>{{ __('panel.orders.methods.online') }}</option>
            </select>
            <label class="row small muted" style="gap:6px">{{ __('panel.orders.from') }}
                <input class="in" style="inline-size:auto" type="date" name="from" value="{{ $filters['from'] }}" data-autosubmit>
            </label>
            <label class="row small muted" style="gap:6px">{{ __('panel.orders.to') }}
                <input class="in" style="inline-size:auto" type="date" name="to" value="{{ $filters['to'] }}" data-autosubmit>
            </label>
            <button class="btn-p btn-p--sm" type="submit">{{ __('panel.common.filter') }}</button>
            @if ($filters['q'] !== '' || $filters['payment'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' || $filters['status'] !== 'all')
                <a class="link-p small" href="{{ route('panel.orders.index') }}">{{ __('panel.common.clearFilters') }}</a>
            @endif
        </form>

        @if ($orders->isEmpty())
            <x-panel.empty icon="orders" :title="__('panel.common.noResults')" :text="__('panel.common.noResultsHint')"/>
        @else
            @include('panel.orders._table', ['orders' => $orders, 'compact' => false])
            <x-panel.pager :paginator="$orders"/>
        @endif
    </section>
@endsection
