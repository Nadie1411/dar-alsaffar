@php
    use App\Support\Money;
    use App\Support\Nav;

    $id     = $order['_id'] ?? $order['orderId'] ?? null;
    $number = $order['orderNumber'] ?? $order['serial'] ?? $id;
    $status = strtolower((string) ($order['status'] ?? $order['orderStatus'] ?? ''));
    $total  = $order['total'] ?? $order['amountToPay'] ?? null;
    $date   = $order['createdAt'] ?? $order['date'] ?? null;

    $tone = $order['statusTone'] ?? match (true) {
        str_contains($status, 'cancel'), str_contains($status, 'reject') => 'cancelled',
        str_contains($status, 'deliver'), str_contains($status, 'complete') => 'done',
        default => 'pending',
    };
@endphp

<div class="order-row">
    <div>
        <p class="order-row__label">{{ __('storefront.account.orderNumber') }}</p>
        <p dir="ltr" style="font-size:var(--step-small)">{{ $number ?: '—' }}</p>
    </div>
    <div>
        <p class="order-row__label">{{ __('storefront.account.orderDate') }}</p>
        <p style="font-size:var(--step-small)">
            {{ $date ? \Illuminate\Support\Carbon::parse($date)->translatedFormat('j M Y') : '—' }}
        </p>
    </div>
    <div>
        <p class="order-row__label">{{ __('storefront.account.orderStatus') }}</p>
        <p class="status status--{{ $tone }}">{{ $order['status'] ?? '—' }}</p>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:var(--space-3)">
        <div>
            <p class="order-row__label">{{ __('storefront.account.orderTotal') }}</p>
            <p style="font-size:var(--step-small)">
                {{ $total !== null ? Money::format($total, $order['symbol'] ?? null) : '—' }}
            </p>
        </div>
        @if ($id)
            <a class="link-underline" href="{{ Nav::url('account/orders/'.$id) }}">
                {{ __('storefront.account.viewOrder') }}
            </a>
        @endif
    </div>
</div>
