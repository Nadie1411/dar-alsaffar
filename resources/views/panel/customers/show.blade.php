@extends('panel.layout')

@section('title', $customer->name)

@section('content')
    @php
        use App\Support\PanelFormat;

        $whatsapp = $customer->phone ? preg_replace('/\D+/', '', $customer->phone) : null;
    @endphp

    <x-panel.page-head :title="$customer->name" :back="route('panel.customers.index')" :backLabel="__('panel.modules.customers')"
                       :sub="__('panel.customers.memberSince', ['date' => PanelFormat::date($customer->created_at)])"/>

    <div class="grid grid--4">
        <x-panel.stat icon="orders" :label="__('panel.customers.orders')" :value="$countedOrders"/>
        <x-panel.stat icon="payments" :label="__('panel.customers.spent')" :value="PanelFormat::amount($spent)" :unit="__('panel.common.currency')"/>
        <x-panel.stat icon="reports" :label="__('panel.customers.average')" :value="PanelFormat::amount($average)" :unit="__('panel.common.currency')"/>
        <x-panel.stat icon="bell" :label="__('panel.customers.lastOrder')" :value="$lastOrder ? PanelFormat::date($lastOrder) : '—'"/>
    </div>

    <div class="grid grid--main">
        <section class="card">
            <div class="card__head"><h2>{{ __('panel.modules.orders') }}</h2></div>
            @if ($orders->isEmpty())
                <x-panel.empty icon="orders" :title="__('panel.customers.noOrders')"/>
            @else
                @include('panel.orders._table', ['orders' => $orders, 'compact' => false])
            @endif
        </section>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.orders.customer') }}</h2></div>
                <div class="card__body stack" style="--gap:10px">
                    <a class="link-p" href="mailto:{{ $customer->email }}"><span class="ltr">{{ $customer->email }}</span></a>
                    @if ($customer->phone)
                        <div class="row small">
                            <a class="link-p" href="tel:{{ $customer->phone }}"><span class="ltr">{{ $customer->phone }}</span></a>
                            <a class="link-p" href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">{{ __('panel.orders.whatsapp') }}</a>
                        </div>
                    @endif
                    <p class="small muted">{{ $customer->marketing_opt_in ? __('panel.customers.optedIn') : __('panel.customers.optedOut') }}</p>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.customers.addresses') }}</h2></div>
                <div class="card__body stack" style="--gap:12px">
                    @forelse ($customer->addresses as $address)
                        <p class="small">
                            {{ collect([
                                app()->getLocale() === 'ar' ? $address->city?->name_ar : $address->city?->name_en,
                                app()->getLocale() === 'ar' ? $address->area?->name_ar : $address->area?->name_en,
                                $address->block ? __('storefront.checkout.block').' '.$address->block : null,
                                $address->street, $address->avenue, $address->building,
                            ])->filter()->implode('، ') ?: '—' }}
                        </p>
                    @empty
                        <p class="muted small">{{ __('panel.customers.noAddresses') }}</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
