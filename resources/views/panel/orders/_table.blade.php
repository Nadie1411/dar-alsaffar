@php
    use App\Support\PanelFormat;
    use App\Support\PanelNav;

    $compact ??= false;
@endphp

<div class="table-wrap">
    <table class="table">
        <thead>
        <tr>
            <th>{{ __('panel.orders.number') }}</th>
            <th>{{ __('panel.orders.customer') }}</th>
            @unless ($compact)<th>{{ __('panel.orders.placed') }}</th>@endunless
            <th>{{ __('panel.orders.payment') }}</th>
            <th>{{ __('panel.common.status') }}</th>
            <th class="col-num">{{ __('panel.common.total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($orders as $order)
            <tr>
                <td>
                    <a class="cell-main" href="{{ route('panel.orders.show', $order) }}"><span class="ltr">{{ $order->number }}</span></a>
                    @if ($compact)<span class="cell-sub">{{ PanelFormat::ago($order->placed_at ?? $order->created_at) }}</span>@endif
                </td>
                <td>
                    {{ $order->customer_name }}
                    <span class="cell-sub"><span class="ltr">{{ $order->customer_phone }}</span></span>
                </td>
                @unless ($compact)
                    <td class="nowrap">
                        {{ PanelFormat::dateTime($order->placed_at ?? $order->created_at) }}
                        <span class="cell-sub">{{ PanelFormat::ago($order->placed_at ?? $order->created_at) }}</span>
                    </td>
                @endunless
                <td>
                    {{ __('panel.orders.methods.'.$order->payment_method) }}
                    <span class="cell-sub"><x-panel.status-pill :status="$order->payment_status" plain/></span>
                </td>
                <td><x-panel.status-pill :status="$order->status"/></td>
                <td class="col-num">{{ PanelFormat::money($order->total_fils) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
