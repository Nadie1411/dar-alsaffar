@extends('panel.layout')

@section('title', __('panel.modules.customers'))

@section('content')
    @php use App\Support\PanelFormat; @endphp

    <x-panel.page-head :title="__('panel.modules.customers')" :sub="__('panel.customers.sub')">
        <a class="btn-p btn-p--ghost" href="{{ route('panel.customers.export', array_filter(['q' => $q])) }}"><x-panel.icon name="download" :size="18"/>{{ __('panel.common.export') }}</a>
    </x-panel.page-head>

    <section class="card">
        <form class="toolbar" method="GET" action="{{ route('panel.customers.index') }}">
            <div class="search">
                <x-panel.icon name="search" :size="18"/>
                <input class="in" type="search" name="q" value="{{ $q }}" placeholder="{{ __('panel.customers.searchHint') }}" aria-label="{{ __('panel.common.search') }}">
            </div>
            <button class="btn-p btn-p--sm" type="submit">{{ __('panel.common.search') }}</button>
            @if ($q !== '')<a class="link-p small" href="{{ route('panel.customers.index') }}">{{ __('panel.common.clearFilters') }}</a>@endif
        </form>

        @if ($customers->isEmpty())
            <x-panel.empty icon="customers" :title="$q !== '' ? __('panel.common.noResults') : __('panel.customers.none')" :text="$q !== '' ? __('panel.common.noResultsHint') : __('panel.customers.noneHint')"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('panel.common.name') }}</th>
                        <th>{{ __('panel.orders.phone') }}</th>
                        <th class="col-num">{{ __('panel.customers.orders') }}</th>
                        <th class="col-num">{{ __('panel.customers.spent') }}</th>
                        <th>{{ __('panel.customers.joined') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($customers as $customer)
                        <tr>
                            <td>
                                <a class="cell-main" href="{{ route('panel.customers.show', $customer) }}">{{ $customer->name }}</a>
                                <span class="cell-sub"><span class="ltr">{{ $customer->email }}</span></span>
                            </td>
                            <td><span class="ltr">{{ $customer->phone ?: '—' }}</span></td>
                            <td class="col-num">{{ $customer->orders_count }}</td>
                            <td class="col-num">{{ PanelFormat::money((int) $customer->orders_sum_total_fils) }}</td>
                            <td>{{ PanelFormat::date($customer->created_at) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-panel.pager :paginator="$customers"/>
        @endif
    </section>
@endsection
