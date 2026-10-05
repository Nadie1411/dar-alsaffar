@extends('panel.layout')

@section('title', $order->number)

@section('content')
    @php
        use App\Enums\OrderStatus;
        use App\Enums\PaymentState;
        use App\Enums\PaymentStatus;
        use App\Support\PanelFormat;
        use App\Support\Media;
        use App\Support\PanelNav;

        $locale = app()->getLocale();
        $name = fn ($ar, $en) => $locale === 'ar' ? ($ar ?: $en) : ($en ?: $ar);
        $whatsapp = preg_replace('/\D+/', '', $order->customer_phone);
        $canSeePayments = auth('staff')->user()->canAccess(\App\Enums\PanelModule::Payments);
    @endphp

    <x-panel.page-head :title="$order->number" :back="route('panel.orders.index')" :backLabel="__('panel.modules.orders')"
                       :sub="PanelFormat::dateTime($order->placed_at ?? $order->created_at)">
        <x-panel.status-pill :status="$order->status"/>
        <a class="btn-p btn-p--ghost" href="{{ route('panel.orders.print', $order) }}" target="_blank" rel="noopener">
            <x-panel.icon name="print" :size="18"/>{{ __('panel.common.print') }}
        </a>
    </x-panel.page-head>

    <div class="grid grid--main">
        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.orders.items') }}</h2></div>
                <div class="table-wrap">
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
                                    <div class="row" style="flex-wrap:nowrap">
                                        @if ($image = Media::url($item->image))
                                            <img class="thumb" src="{{ $image }}" alt="" loading="lazy">
                                        @endif
                                        <div>
                                            @if ($item->product_id && Route::has('panel.products.edit'))
                                                <a class="cell-main" href="{{ route('panel.products.edit', $item->product_id) }}">{{ $item->localized('name') }}</a>
                                            @else
                                                <strong>{{ $item->localized('name') }}</strong>
                                            @endif
                                            @if ($item->sku)<span class="cell-sub"><span class="ltr">{{ $item->sku }}</span></span>@endif
                                            @foreach ($item->options ?? [] as $option)
                                                <span class="cell-sub">
                                                    {{ $name($option['group']['ar'] ?? '', $option['group']['en'] ?? '') }}:
                                                    {{ collect($option['values'] ?? [])->map(fn ($value) => $name($value['name']['ar'] ?? '', $value['name']['en'] ?? '').(($value['quantity'] ?? 1) > 1 ? ' × '.$value['quantity'] : ''))->implode('، ') }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                </td>
                                <td class="col-num">{{ PanelFormat::amount($item->unit_price_fils) }}</td>
                                <td class="col-num">{{ $item->quantity }}</td>
                                <td class="col-num">{{ PanelFormat::amount($item->total_fils) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card__body">
                    <dl class="kv">
                        <div><dt>{{ __('panel.orders.subtotal') }}</dt><dd>{{ PanelFormat::money($order->subtotal_fils) }}</dd></div>
                        @if ($order->discount_fils > 0)
                            <div class="discount"><dt>{{ __('panel.orders.discount') }}@if ($order->voucher_code) <span class="ltr small">({{ $order->voucher_code }})</span>@endif</dt><dd>− {{ PanelFormat::money($order->discount_fils) }}</dd></div>
                        @endif
                        <div><dt>{{ __('panel.orders.delivery') }}</dt><dd>{{ $order->delivery_fee_fils > 0 ? PanelFormat::money($order->delivery_fee_fils) : __('panel.orders.free') }}</dd></div>
                        @if ($order->addons_total_fils > 0)
                            <div><dt>{{ __('panel.orders.addons') }}</dt><dd>{{ PanelFormat::money($order->addons_total_fils) }}</dd></div>
                        @endif
                        @if ($order->cod_fee_fils > 0)
                            <div><dt>{{ __('panel.orders.codFee') }}</dt><dd>{{ PanelFormat::money($order->cod_fee_fils) }}</dd></div>
                        @endif
                        <div class="total"><dt>{{ __('panel.common.total') }}</dt><dd>{{ PanelFormat::money($order->total_fils) }}</dd></div>
                    </dl>
                    @if (! empty($order->addons))
                        <p class="small muted" style="margin-block-start:12px">
                            {{ __('panel.orders.addons') }}: {{ collect($order->addons)->map(fn ($addon) => $name($addon['name_ar'] ?? '', $addon['name_en'] ?? ''))->implode('، ') }}
                        </p>
                    @endif
                </div>
            </section>

            @if ($order->payments->isNotEmpty())
                <section class="card">
                    <div class="card__head">
                        <h2>{{ __('panel.orders.paymentAttempts') }}</h2>
                        @if ($canSeePayments && ($paymentsUrl = PanelNav::link('panel.payments.index', ['q' => $order->number])))
                            <a class="link-p small" href="{{ $paymentsUrl }}">{{ __('panel.orders.openPayments') }}</a>
                        @endif
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('panel.common.date') }}</th>
                                <th>{{ __('panel.orders.gatewayMethod') }}</th>
                                <th>{{ __('panel.common.status') }}</th>
                                <th>{{ __('panel.orders.invoice') }}</th>
                                <th class="col-num">{{ __('panel.payments.amount') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($order->payments as $payment)
                                <tr>
                                    <td>{{ PanelFormat::dateTime($payment->created_at) }}</td>
                                    <td>{{ $payment->mf_method ?: ($payment->method ?: '—') }}</td>
                                    <td>
                                        <x-panel.pill :tone="match ($payment->state) { PaymentState::Paid => 'green', PaymentState::Pending => 'amber', PaymentState::Failed, PaymentState::Cancelled, PaymentState::Expired => 'red' }">
                                            {{ __('panel.payments.states.'.$payment->state->value) }}
                                        </x-panel.pill>
                                        @if ($payment->failure_reason)<span class="cell-sub">{{ $payment->failure_reason }}</span>@endif
                                        @if ($payment->anomaly)<span class="cell-sub" style="color:var(--panel-warn)">{{ __('panel.payments.anomalies.'.$payment->anomaly) }}</span>@endif
                                    </td>
                                    <td><span class="ltr">{{ $payment->mf_invoice_id ?: '—' }}</span></td>
                                    <td class="col-num">{{ PanelFormat::amount($payment->amount_fils) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.orders.history') }}</h2></div>
                <div class="card__body">
                    <ol class="timeline">
                        @foreach ($order->statusChanges->reverse() as $change)
                            <li>
                                <strong>{{ $change->to_status->panelLabel() }}</strong>
                                <span class="timeline__when">
                                    {{ PanelFormat::dateTime($change->created_at) }}
                                    · {{ $change->staff ? __('panel.common.by', ['name' => $change->staff->name]) : __('panel.common.system') }}
                                </span>
                                @if ($change->note)<p class="small muted">{{ $change->note }}</p>@endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.orders.updateStatus') }}</h2></div>
                <div class="card__body">
                    @if ($transitions === [])
                        <p class="muted">{{ __('panel.orders.finalStatus') }}</p>
                    @else
                        @if ($order->status === OrderStatus::PendingPayment)
                            <p class="alert-p alert-p--warn" style="margin-block-end:14px">{{ __('panel.orders.awaitingPayment') }}</p>
                        @endif
                        <form class="stack" method="POST" action="{{ route('panel.orders.status', $order) }}" style="--gap:12px">
                            @csrf
                            <div class="field-p">
                                <label for="status">{{ __('panel.orders.moveTo') }}</label>
                                <select class="sel" id="status" name="status">
                                    @foreach ($transitions as $transition)
                                        <option value="{{ $transition->value }}">{{ $transition->panelLabel() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field-p">
                                <label for="note">{{ __('panel.orders.statusNote') }} <span class="muted small">({{ __('panel.common.optional') }})</span></label>
                                <input class="in" id="note" name="note" maxlength="500">
                            </div>
                            <button class="btn-p" type="submit"
                                    data-confirm="{{ __('panel.orders.confirmMove') }}">{{ __('panel.orders.update') }}</button>
                        </form>
                    @endif
                </div>
            </section>

            <section class="card">
                <div class="card__head">
                    <h2>{{ __('panel.orders.payment') }}</h2>
                    <x-panel.status-pill :status="$order->payment_status"/>
                </div>
                <div class="card__body stack" style="--gap:12px">
                    <dl class="kv">
                        <div><dt>{{ __('panel.orders.method') }}</dt><dd>{{ __('panel.orders.methods.'.$order->payment_method) }}</dd></div>
                        @if ($order->paid_at)<div><dt>{{ __('panel.orders.paidAt') }}</dt><dd>{{ PanelFormat::dateTime($order->paid_at) }}</dd></div>@endif
                    </dl>

                    @if ($order->isCashOnDelivery() && $order->payment_status === PaymentStatus::Unpaid && $order->status !== OrderStatus::Cancelled)
                        <form method="POST" action="{{ route('panel.orders.paid', $order) }}">
                            @csrf
                            <button class="btn-p btn-p--ghost btn-p--block" type="submit" data-confirm="{{ __('panel.orders.confirmMarkPaid') }}">{{ __('panel.orders.markPaid') }}</button>
                        </form>
                    @endif

                    @if ($order->payment_status === PaymentStatus::Paid && $order->status === OrderStatus::Cancelled && ! $order->isCashOnDelivery())
                        <p class="alert-p alert-p--warn">{{ __('panel.orders.refundHint') }}</p>
                        <form method="POST" action="{{ route('panel.orders.refunded', $order) }}">
                            @csrf
                            <button class="btn-p btn-p--ghost btn-p--block" type="submit" data-confirm="{{ __('panel.orders.confirmMarkRefunded') }}">{{ __('panel.orders.markRefunded') }}</button>
                        </form>
                    @endif
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.orders.customer') }}</h2></div>
                <div class="card__body stack" style="--gap:10px">
                    <strong>{{ $order->customer_name }}</strong>
                    <div class="row small">
                        <a class="link-p" href="tel:{{ $order->customer_phone }}"><span class="ltr">{{ $order->customer_phone }}</span></a>
                        <a class="link-p" href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">{{ __('panel.orders.whatsapp') }}</a>
                    </div>
                    @if ($order->customer_email)<a class="link-p small" href="mailto:{{ $order->customer_email }}"><span class="ltr">{{ $order->customer_email }}</span></a>@endif
                    @if ($order->customer_id && Route::has('panel.customers.show'))
                        <a class="link-p small" href="{{ route('panel.customers.show', $order->customer_id) }}">
                            {{ __('panel.orders.customerAccount') }} · {{ trans_choice('panel.dashboard.orders', $customerOrders) }}
                        </a>
                    @elseif (! $order->customer_id)
                        <span class="muted small">{{ __('panel.orders.guest') }}</span>
                    @endif
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.orders.deliveryTo') }}</h2></div>
                <div class="card__body stack" style="--gap:8px">
                    <p>{{ $order->localizedAddress() ?: '—' }}</p>
                    @if ($order->floor || $order->apartment)
                        <p class="small muted">
                            @if ($order->floor){{ __('storefront.checkout.floor') }} {{ $order->floor }}@endif
                            @if ($order->apartment) · {{ __('storefront.checkout.apartment') }} {{ $order->apartment }}@endif
                        </p>
                    @endif
                    @if ($order->notes)
                        <div><span class="field-p__label">{{ __('panel.orders.customerNotes') }}</span><p class="small">{{ $order->notes }}</p></div>
                    @endif
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.orders.internalNote') }}</h2></div>
                <form class="card__body stack" method="POST" action="{{ route('panel.orders.note', $order) }}" style="--gap:12px">
                    @csrf
                    <textarea class="ta" name="admin_notes" rows="3" maxlength="2000" aria-label="{{ __('panel.orders.internalNote') }}">{{ old('admin_notes', $order->admin_notes) }}</textarea>
                    <span class="field-p__hint">{{ __('panel.orders.internalNoteHint') }}</span>
                    <button class="btn-p btn-p--ghost" type="submit">{{ __('panel.orders.saveNote') }}</button>
                </form>
            </section>
        </div>
    </div>
@endsection
