@extends('panel.layout')

@section('title', $order->number)

@section('content')
    @php
        use App\Support\Media;
        use App\Support\PanelFormat;

        $locale = app()->getLocale();
        $name = fn ($ar, $en) => $locale === 'ar' ? ($ar ?: $en) : ($en ?: $ar);
    @endphp

    <x-panel.page-head :title="$order->number" :back="route('panel.orders.show', $order)" :backLabel="__('panel.common.back')">
        <button class="btn-p" type="button" data-print><x-panel.icon name="print" :size="18"/>{{ __('panel.common.print') }}</button>
    </x-panel.page-head>

    <article class="card">
        <div class="card__body stack" style="--gap:22px">
            <header class="row row--between row--start">
                <div>
                    <h2 style="font-family:var(--font-display);font-size:26px;font-weight:400">{{ config('brand.name.'.$locale, config('brand.name.ar')) }}</h2>
                    <p class="muted small">{{ __('panel.orders.invoice') }} <span class="ltr">{{ $order->number }}</span> · {{ PanelFormat::dateTime($order->placed_at ?? $order->created_at) }}</p>
                </div>
                <div class="text-end small">
                    <strong>{{ __('panel.orders.method') }}:</strong> {{ __('panel.orders.methods.'.$order->payment_method) }}<br>
                    <strong>{{ __('panel.orders.paymentStatus') }}:</strong> {{ $order->payment_status->label() }}
                </div>
            </header>

            <div class="grid grid--2">
                <div>
                    <p class="field-p__label">{{ __('panel.orders.customer') }}</p>
                    <p>{{ $order->customer_name }}</p>
                    <p><span class="ltr">{{ $order->customer_phone }}</span></p>
                    @if ($order->customer_email)<p><span class="ltr">{{ $order->customer_email }}</span></p>@endif
                </div>
                <div>
                    <p class="field-p__label">{{ __('panel.orders.deliveryTo') }}</p>
                    <p>{{ $order->localizedAddress() }}</p>
                    @if ($order->floor || $order->apartment)
                        <p>@if ($order->floor){{ __('storefront.checkout.floor') }} {{ $order->floor }}@endif @if ($order->apartment) · {{ __('storefront.checkout.apartment') }} {{ $order->apartment }}@endif</p>
                    @endif
                </div>
            </div>

            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('panel.orders.product') }}</th>
                    <th class="col-num">{{ __('panel.orders.unitPrice') }}</th>
                    <th class="col-num">{{ __('panel.orders.quantity') }}</th>
                    <th class="col-num">{{ __('panel.common.total') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>
                            <strong>{{ $item->localized('name') }}</strong>
                            @if ($item->sku)<span class="cell-sub"><span class="ltr">{{ $item->sku }}</span></span>@endif
                            @foreach ($item->options ?? [] as $option)
                                <span class="cell-sub">
                                    {{ $name($option['group']['ar'] ?? '', $option['group']['en'] ?? '') }}:
                                    {{ collect($option['values'] ?? [])->map(fn ($value) => $name($value['name']['ar'] ?? '', $value['name']['en'] ?? '').(($value['quantity'] ?? 1) > 1 ? ' × '.$value['quantity'] : ''))->implode('، ') }}
                                </span>
                            @endforeach
                        </td>
                        <td class="col-num">{{ PanelFormat::amount($item->unit_price_fils) }}</td>
                        <td class="col-num">{{ $item->quantity }}</td>
                        <td class="col-num">{{ PanelFormat::amount($item->total_fils) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <dl class="kv" style="max-inline-size:340px;margin-inline-start:auto">
                <div><dt>{{ __('panel.orders.subtotal') }}</dt><dd>{{ PanelFormat::money($order->subtotal_fils) }}</dd></div>
                @if ($order->discount_fils > 0)<div class="discount"><dt>{{ __('panel.orders.discount') }}</dt><dd>− {{ PanelFormat::money($order->discount_fils) }}</dd></div>@endif
                <div><dt>{{ __('panel.orders.delivery') }}</dt><dd>{{ $order->delivery_fee_fils > 0 ? PanelFormat::money($order->delivery_fee_fils) : __('panel.orders.free') }}</dd></div>
                @if ($order->addons_total_fils > 0)<div><dt>{{ __('panel.orders.addons') }}</dt><dd>{{ PanelFormat::money($order->addons_total_fils) }}</dd></div>@endif
                @if ($order->cod_fee_fils > 0)<div><dt>{{ __('panel.orders.codFee') }}</dt><dd>{{ PanelFormat::money($order->cod_fee_fils) }}</dd></div>@endif
                <div class="total"><dt>{{ __('panel.common.total') }}</dt><dd>{{ PanelFormat::money($order->total_fils) }}</dd></div>
            </dl>

            @if ($order->notes)
                <div><p class="field-p__label">{{ __('panel.orders.customerNotes') }}</p><p>{{ $order->notes }}</p></div>
            @endif
        </div>
    </article>
@endsection
