@extends('panel.layout')

@section('title', __('panel.modules.payments'))

@section('content')
    @php
        use App\Enums\PaymentState;
        use App\Support\PanelFormat;

        $tone = fn (PaymentState $state) => match ($state) {
            PaymentState::Paid => 'green',
            PaymentState::Pending => 'amber',
            default => 'red',
        };
    @endphp

    <x-panel.page-head :title="__('panel.modules.payments')" :sub="__('panel.payments.sub')"/>

    <div class="grid grid--main">
        <div class="stack">
            @if ($needReview > 0)
                <div class="alert-p alert-p--warn">
                    <x-panel.icon name="alert" :size="18"/>
                    <span>{{ trans_choice('panel.payments.reviewBanner', $needReview) }}
                        <a class="link-p" href="{{ route('panel.payments.index', ['review' => 1]) }}">{{ __('panel.payments.reviewThem') }}</a></span>
                </div>
            @endif

            <section class="card">
                <form class="toolbar" method="GET" action="{{ route('panel.payments.index') }}">
                    <div class="search">
                        <x-panel.icon name="search" :size="18"/>
                        <input class="in" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('panel.payments.searchHint') }}" aria-label="{{ __('panel.common.search') }}">
                    </div>
                    <select class="sel" name="state" aria-label="{{ __('panel.common.status') }}" data-autosubmit>
                        <option value="">{{ __('panel.payments.anyState') }}</option>
                        @foreach (PaymentState::cases() as $state)
                            <option value="{{ $state->value }}" @selected($filters['state'] === $state->value)>{{ __('panel.payments.states.'.$state->value) }}</option>
                        @endforeach
                    </select>
                    <label class="check"><input type="checkbox" name="review" value="1" @checked($filters['review']) data-autosubmit> {{ __('panel.payments.onlyReview') }}</label>
                    <button class="btn-p btn-p--sm" type="submit">{{ __('panel.common.filter') }}</button>
                    @if ($filters['q'] !== '' || $filters['state'] !== '' || $filters['review'])
                        <a class="link-p small" href="{{ route('panel.payments.index') }}">{{ __('panel.common.clearFilters') }}</a>
                    @endif
                </form>

                @if ($payments->isEmpty())
                    <x-panel.empty icon="payments" :title="__('panel.payments.none')" :text="__('panel.payments.noneHint')"/>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('panel.orders.number') }}</th>
                                <th>{{ __('panel.common.date') }}</th>
                                <th>{{ __('panel.orders.gatewayMethod') }}</th>
                                <th>{{ __('panel.common.status') }}</th>
                                <th>{{ __('panel.orders.invoice') }}</th>
                                <th class="col-num">{{ __('panel.payments.amount') }}</th>
                                <th class="col-actions">{{ __('panel.common.actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($payments as $payment)
                                <tr>
                                    <td>
                                        @if ($payment->order)
                                            <a class="cell-main" href="{{ route('panel.orders.show', $payment->order) }}"><span class="ltr">{{ $payment->order->number }}</span></a>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                    <td class="nowrap">{{ PanelFormat::dateTime($payment->created_at) }}</td>
                                    <td>{{ $payment->mf_method ?: ($payment->method ?: '—') }}</td>
                                    <td>
                                        <x-panel.pill :tone="$tone($payment->state)">{{ __('panel.payments.states.'.$payment->state->value) }}</x-panel.pill>
                                        @if ($payment->failure_reason)<span class="cell-sub">{{ $payment->failure_reason }}</span>@endif
                                        @if ($payment->anomaly)
                                            <span class="cell-sub" style="color: var(--panel-warn)">
                                                {{ __('panel.payments.anomalies.'.$payment->anomaly) }}
                                                @if ($payment->anomaly_reviewed_at)
                                                    · {{ __('panel.payments.reviewedOn', ['date' => PanelFormat::date($payment->anomaly_reviewed_at)]) }}
                                                @endif
                                            </span>
                                        @endif
                                    </td>
                                    <td><span class="ltr">{{ $payment->mf_invoice_id ?: '—' }}</span></td>
                                    <td class="col-num">{{ PanelFormat::amount($payment->amount_fils) }}</td>
                                    <td class="col-actions">
                                        @if ($payment->state === PaymentState::Pending && $gateway['configured'])
                                            <form method="POST" action="{{ route('panel.payments.verify', $payment) }}" style="display:inline">
                                                @csrf
                                                <button class="btn-p btn-p--ghost btn-p--sm" type="submit">{{ __('panel.payments.checkAgain') }}</button>
                                            </form>
                                        @endif
                                        @if ($payment->anomaly && ! $payment->anomaly_reviewed_at)
                                            <form method="POST" action="{{ route('panel.payments.review', $payment) }}" style="display:inline">
                                                @csrf
                                                <button class="btn-p btn-p--ghost btn-p--sm" type="submit">{{ __('panel.payments.markReviewed') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-panel.pager :paginator="$payments"/>
                @endif
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head">
                    <h2>{{ __('panel.payments.gateway') }}</h2>
                    @if ($gateway['configured'])
                        <x-panel.pill :tone="$gateway['sandbox'] ? 'amber' : 'green'">{{ $gateway['sandbox'] ? __('panel.payments.sandbox') : __('panel.payments.live') }}</x-panel.pill>
                    @else
                        <x-panel.pill tone="grey">{{ __('panel.payments.off') }}</x-panel.pill>
                    @endif
                </div>
                <div class="card__body stack" style="--gap:14px">
                    <dl class="kv">
                        <div><dt>{{ __('panel.payments.provider') }}</dt><dd>MyFatoorah</dd></div>
                        <div><dt>{{ __('panel.payments.endpoint') }}</dt><dd><span class="ltr">{{ $gateway['host'] }}</span></dd></div>
                        <div><dt>{{ __('panel.payments.apiKey') }}</dt><dd>{{ $gateway['hasKey'] ? __('panel.payments.keySet') : __('panel.payments.keyMissing') }}@if ($gateway['hasKey']) <span class="muted small">({{ __('panel.gateway.sources.'.$gateway['keySource']) }})</span>@endif</dd></div>
                        <div><dt>{{ __('panel.payments.webhookSecret') }}</dt><dd>{{ $gateway['secretSet'] ? __('panel.payments.keySet') : __('panel.payments.keyMissing') }}@if ($gateway['secretSet']) <span class="muted small">({{ __('panel.gateway.sources.'.$gateway['secretSource']) }})</span>@endif</dd></div>
                    </dl>

                    @if ($gateway['unreadable'])
                        <p class="alert-p alert-p--err">{{ __('panel.gateway.unreadable') }}</p>
                    @endif

                    @unless ($gateway['configured'])
                        <p class="alert-p alert-p--warn">{{ $canManageKeys ? __('panel.payments.setupHintOwner') : __('panel.payments.setupHint') }}</p>
                    @endunless

                    @if ($canManageKeys)
                        <a class="btn-p btn-p--ghost btn-p--block" href="{{ route('panel.payments.gateway.edit') }}"><x-panel.icon name="lock" :size="18"/>{{ __('panel.gateway.manage') }}</a>
                    @endif

                    <div class="field-p">
                        <span class="field-p__label">{{ __('panel.payments.webhookUrl') }}</span>
                        <div class="row" style="flex-wrap:nowrap">
                            <input class="in in--ltr" readonly value="{{ $gateway['webhookUrl'] }}" aria-label="{{ __('panel.payments.webhookUrl') }}" data-select>
                            <button class="btn-p btn-p--ghost btn-p--sm" type="button" data-copy="{{ $gateway['webhookUrl'] }}" data-copied="{{ __('panel.payments.copied') }}">{{ __('panel.payments.copy') }}</button>
                        </div>
                        <span class="field-p__hint">{{ __('panel.payments.webhookHint') }}</span>
                    </div>

                    @if ($gateway['configured'])
                        <form method="POST" action="{{ route('panel.payments.test') }}">
                            @csrf
                            <button class="btn-p btn-p--ghost btn-p--block" type="submit">{{ __('panel.payments.testConnection') }}</button>
                        </form>
                    @endif
                </div>
            </section>

            <form class="card" method="POST" action="{{ route('panel.payments.settings') }}">
                @csrf @method('PUT')
                <div class="card__head"><h2>{{ __('panel.payments.cash') }}</h2></div>
                <div class="card__body stack" style="--gap:14px">
                    <x-panel.switch name="cod_enabled" :label="__('panel.payments.codOn')" :checked="old('cod_enabled', $codEnabled)" :hint="__('panel.payments.codHint')"/>
                    <x-panel.input name="cod_fee" :label="__('panel.payments.codFee')" :value="old('cod_fee', $codFee)" inputmode="decimal" ltr :suffix="__('panel.common.currency')" :hint="__('panel.payments.codFeeHint')"/>
                </div>
                <div class="card__foot"><button class="btn-p" type="submit">{{ __('panel.common.saveChanges') }}</button></div>
            </form>
        </div>
    </div>
@endsection
