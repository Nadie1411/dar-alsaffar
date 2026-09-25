@php use App\Support\Money; @endphp

@forelse ($orders as $order)
    <article class="order-item" data-order-id="{{ $order['id'] }}">
        <div class="order-item__main">
            <span class="order-item__number" dir="ltr">#{{ $order['number'] }}</span>
            <span class="badge badge--new order-item__new">{{ __('storefront.admin.ordersNew') }}</span>
            @if ($order['customer'])
                <span class="order-item__customer">{{ $order['customer'] }}</span>
            @endif
            @if ($order['phone'])
                <a class="order-item__phone" dir="ltr" href="tel:{{ $order['phone'] }}">{{ $order['phone'] }}</a>
            @endif
        </div>

        <div class="order-item__meta">
            @if ($order['placedAt'])
                <time datetime="{{ $order['placedAt'] }}">
                    {{ \Illuminate\Support\Carbon::parse($order['placedAt'])->diffForHumans() }}
                </time>
            @endif
            @if ($order['itemCount'])
                <span>{{ __('storefront.admin.ordersItems', ['count' => $order['itemCount']]) }}</span>
            @endif
            @if ($order['isCash'])
                <span class="badge badge--quiet">{{ __('storefront.admin.ordersCash') }}</span>
            @endif
            @if ($order['isPickup'])
                <span class="badge badge--quiet">{{ __('storefront.admin.ordersPickup') }}</span>
            @endif
            @if ($order['status'])
                <span class="status status--pending">{{ $order['status'] }}</span>
            @endif
        </div>

        <div class="order-item__total">{{ Money::format($order['total'], $order['symbol']) }}</div>
    </article>
@empty
    <p class="admin-empty">{{ __('storefront.admin.ordersNone') }}</p>
@endforelse
