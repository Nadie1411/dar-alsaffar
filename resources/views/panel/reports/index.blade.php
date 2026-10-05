@extends('panel.layout')

@section('title', __('panel.modules.reports'))

@section('content')
    @php
        use App\Enums\OrderStatus;
        use App\Support\PanelFormat;

        $presets = ['today', '7d', '30d', 'month', 'last_month'];
        $name = fn ($row) => app()->getLocale() === 'ar' ? ($row->name_ar ?: $row->name_en) : ($row->name_en ?: $row->name_ar);
        $exportQuery = $preset === 'custom'
            ? ['range' => 'custom', 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
            : ['range' => $preset];
    @endphp

    <x-panel.page-head :title="__('panel.modules.reports')" :sub="PanelFormat::date($from).' — '.PanelFormat::date($to)">
        <a class="btn-p btn-p--ghost" href="{{ route('panel.reports.export', $exportQuery) }}"><x-panel.icon name="download" :size="18"/>{{ __('panel.common.export') }}</a>
    </x-panel.page-head>

    <section class="card">
        <nav class="tabs" aria-label="{{ __('panel.reports.period') }}">
            @foreach ($presets as $option)
                <a class="tab {{ $preset === $option ? 'is-active' : '' }}" href="{{ route('panel.reports.index', ['range' => $option]) }}">{{ __('panel.reports.presets.'.$option) }}</a>
            @endforeach
        </nav>
        <form class="toolbar" method="GET" action="{{ route('panel.reports.index') }}">
            <input type="hidden" name="range" value="custom">
            <label class="row small muted" style="gap:6px">{{ __('panel.orders.from') }}
                <input class="in" style="inline-size:auto" type="date" name="from" value="{{ $from->format('Y-m-d') }}" required>
            </label>
            <label class="row small muted" style="gap:6px">{{ __('panel.orders.to') }}
                <input class="in" style="inline-size:auto" type="date" name="to" value="{{ $to->format('Y-m-d') }}" required>
            </label>
            <button class="btn-p btn-p--sm" type="submit">{{ __('panel.reports.apply') }}</button>
        </form>
    </section>

    <div class="grid grid--4">
        <x-panel.stat icon="payments" :label="__('panel.reports.revenue')" :value="PanelFormat::amount($summary['revenue'])" :unit="__('panel.common.currency')"/>
        <x-panel.stat icon="orders" :label="__('panel.reports.orders')" :value="$summary['orders']"/>
        <x-panel.stat icon="reports" :label="__('panel.reports.average')" :value="PanelFormat::amount($summary['average'])" :unit="__('panel.common.currency')"/>
        <x-panel.stat icon="vouchers" :label="__('panel.reports.discounts')" :value="PanelFormat::amount($summary['discounts'])" :unit="__('panel.common.currency')"
                      :note="__('panel.reports.deliveryCollected', ['amount' => PanelFormat::money($summary['delivery'])])"/>
    </div>

    <section class="card">
        <div class="card__head"><h2>{{ __('panel.reports.daily') }}</h2></div>
        <div class="card__body">
            @if ($summary['orders'] === 0)
                <p class="muted">{{ __('panel.dashboard.noSales') }}</p>
            @else
                <x-panel.bar-chart :series="$series" :label="__('panel.reports.daily')"/>
            @endif
        </div>
    </section>

    <div class="grid grid--2">
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.reports.topProducts') }}</h2></div>
            @if ($products === [])
                <p class="muted" style="padding: 20px">{{ __('panel.dashboard.noSales') }}</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>{{ __('panel.orders.product') }}</th>
                            <th class="col-num">{{ __('panel.reports.units') }}</th>
                            <th class="col-num">{{ __('panel.reports.revenue') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($products as $row)
                            <tr>
                                <td>{{ $name($row) }}</td>
                                <td class="col-num">{{ $row->units }}</td>
                                <td class="col-num">{{ PanelFormat::amount($row->revenue) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.reports.byMethod') }}</h2></div>
                <div class="card__body">
                    @forelse ($methods as $method => $row)
                        <div class="row row--between" style="padding-block: 8px">
                            <span>{{ __('panel.orders.methods.'.$method) }} <span class="muted small">({{ $row['orders'] }})</span></span>
                            <strong class="num">{{ PanelFormat::money($row['revenue']) }}</strong>
                        </div>
                    @empty
                        <p class="muted">{{ __('panel.dashboard.noSales') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.reports.byCity') }}</h2></div>
                <div class="card__body">
                    @forelse ($cities as $city => $row)
                        <div class="row row--between" style="padding-block: 8px">
                            <span>{{ $city ?: '—' }} <span class="muted small">({{ $row['orders'] }})</span></span>
                            <strong class="num">{{ PanelFormat::money($row['revenue']) }}</strong>
                        </div>
                    @empty
                        <p class="muted">{{ __('panel.dashboard.noSales') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.reports.pipeline') }}</h2></div>
                <div class="card__body">
                    @foreach (OrderStatus::cases() as $status)
                        <div class="row row--between" style="padding-block: 6px">
                            <x-panel.status-pill :status="$status"/>
                            <strong class="num">{{ $statuses[$status->value] ?? 0 }}</strong>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
@endsection
