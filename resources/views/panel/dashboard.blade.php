@extends('panel.layout')

@section('title', __('panel.modules.dashboard'))

@section('content')
    @php
        use App\Support\PanelFormat;
        use App\Support\PanelNav;
    @endphp

    <x-panel.page-head :title="__('panel.dashboard.greeting', ['name' => $user->name])" :sub="__('panel.dashboard.sub')"/>

    <div class="grid grid--4">
        @if ($canSeeSales)
            <x-panel.stat icon="payments" :label="__('panel.dashboard.salesToday')"
                          :value="PanelFormat::amount($today['revenue'])" :unit="__('panel.common.currency')"
                          :note="trans_choice('panel.dashboard.orders', $today['orders'])"/>
        @endif
        <x-panel.stat icon="orders" :label="__('panel.dashboard.ordersToday')" :value="$today['orders']"/>
        <x-panel.stat icon="bell" :label="__('panel.dashboard.awaiting')" :value="$awaiting"
                      :note="__('panel.dashboard.awaitingNote')" :alert="$awaiting > 0"
                      :href="PanelNav::link('panel.orders.index', ['status' => 'new'])"/>
        @if ($canSeeSales)
            <x-panel.stat icon="reports" :label="__('panel.dashboard.sales30')"
                          :value="PanelFormat::amount($month['revenue'])" :unit="__('panel.common.currency')"
                          :note="trans_choice('panel.dashboard.orders', $month['orders']).' · '.__('panel.dashboard.average', ['amount' => PanelFormat::amount($month['average'])])"/>
        @endif
    </div>

    <div class="grid grid--main">
        <div class="stack">
            @if ($canSeeSales)
                <section class="card">
                    <div class="card__head"><h2>{{ __('panel.dashboard.chart') }}</h2></div>
                    <div class="card__body">
                        @if ($month['orders'] === 0)
                            <p class="muted">{{ __('panel.dashboard.noSales') }}</p>
                        @else
                            <x-panel.bar-chart :series="$series" :label="__('panel.dashboard.chart')"/>
                        @endif
                    </div>
                </section>
            @endif

            <section class="card">
                <div class="card__head">
                    <h2>{{ __('panel.dashboard.recent') }}</h2>
                    @if ($ordersUrl = PanelNav::link('panel.orders.index'))
                        <a class="link-p small" href="{{ $ordersUrl }}">{{ __('panel.common.viewAll') }}</a>
                    @endif
                </div>
                @if ($recentOrders->isEmpty())
                    <x-panel.empty icon="orders" :title="__('panel.dashboard.noOrders')" :text="__('panel.dashboard.noOrdersHint')"/>
                @else
                    @include('panel.orders._table', ['orders' => $recentOrders, 'compact' => true])
                @endif
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.dashboard.attention') }}</h2></div>
                <div class="card__body">
                    @forelse ($attention as $item)
                        <a class="row row--between" style="padding-block: 8px; text-decoration: none" @if ($item['url']) href="{{ $item['url'] }}" @endif>
                            <span>{{ __('panel.dashboard.attentionItems.'.$item['key']) }}</span>
                            <x-panel.pill tone="amber" plain>{{ $item['count'] }}</x-panel.pill>
                        </a>
                    @empty
                        <p class="muted">{{ __('panel.dashboard.allClear') }}</p>
                    @endforelse
                </div>
            </section>

            @if ($canSeeSales)
                <section class="card">
                    <div class="card__head"><h2>{{ __('panel.dashboard.top') }}</h2></div>
                    <div class="card__body card__body--flush">
                        @forelse ($topProducts as $row)
                            <div class="row row--between" style="padding: 12px 20px; border-block-end: 1px solid var(--panel-line)">
                                <div class="grow">
                                    <strong>{{ app()->getLocale() === 'ar' ? ($row->name_ar ?: $row->name_en) : ($row->name_en ?: $row->name_ar) }}</strong>
                                    <span class="cell-sub muted small">{{ trans_choice('panel.dashboard.units', $row->units) }}</span>
                                </div>
                                <span class="num nowrap">{{ PanelFormat::money($row->revenue) }}</span>
                            </div>
                        @empty
                            <p class="muted" style="padding: 20px">{{ __('panel.dashboard.noSales') }}</p>
                        @endforelse
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection
