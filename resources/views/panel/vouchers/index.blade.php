@extends('panel.layout')

@section('title', __('panel.modules.vouchers'))

@section('content')
    @php
        use App\Models\Voucher;
        use App\Support\PanelFormat;

        $filters = ['all', 'live', 'scheduled', 'expired', 'inactive'];
    @endphp

    <x-panel.page-head :title="__('panel.modules.vouchers')" :sub="__('panel.vouchers.sub')">
        <a class="btn-p" href="{{ route('panel.vouchers.create') }}"><x-panel.icon name="plus" :size="18"/>{{ __('panel.vouchers.add') }}</a>
    </x-panel.page-head>

    <section class="card">
        <nav class="tabs" aria-label="{{ __('panel.common.status') }}">
            @foreach ($filters as $name)
                <a class="tab {{ $filter === $name ? 'is-active' : '' }}" href="{{ route('panel.vouchers.index', $name === 'all' ? [] : ['status' => $name]) }}">{{ __('panel.vouchers.filters.'.$name) }}</a>
            @endforeach
        </nav>

        @if ($vouchers->isEmpty())
            <x-panel.empty icon="vouchers" :title="__('panel.vouchers.none')" :text="__('panel.vouchers.noneHint')"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('panel.vouchers.code') }}</th>
                        <th>{{ __('panel.vouchers.offer') }}</th>
                        <th>{{ __('panel.vouchers.validity') }}</th>
                        <th class="col-num">{{ __('panel.vouchers.uses') }}</th>
                        <th>{{ __('panel.common.status') }}</th>
                        <th class="col-actions">{{ __('panel.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($vouchers as $voucher)
                        @php $used = $usage->get($voucher->code); @endphp
                        <tr>
                            <td>
                                <a class="cell-main" href="{{ route('panel.vouchers.edit', $voucher) }}"><span class="ltr">{{ $voucher->code }}</span></a>
                                <span class="cell-sub">{{ $voucher->localized('name') }}</span>
                            </td>
                            <td>
                                @if ($voucher->type === Voucher::TYPE_PERCENTAGE)
                                    {{ rtrim(rtrim(number_format((float) $voucher->percent, 2, '.', ''), '0'), '.') }}%
                                    @if ($voucher->max_discount_fils !== null)<span class="cell-sub">{{ __('panel.vouchers.upTo', ['amount' => PanelFormat::money($voucher->max_discount_fils)]) }}</span>@endif
                                @elseif ($voucher->type === Voucher::TYPE_FIXED)
                                    {{ PanelFormat::money($voucher->amount_fils) }}
                                @else
                                    {{ __('panel.vouchers.types.free_shipping') }}
                                @endif
                                @if ($voucher->min_subtotal_fils > 0)<span class="cell-sub">{{ __('panel.vouchers.minimum', ['amount' => PanelFormat::money($voucher->min_subtotal_fils)]) }}</span>@endif
                            </td>
                            <td class="small">
                                @if ($voucher->starts_at || $voucher->ends_at)
                                    {{ $voucher->starts_at ? PanelFormat::date($voucher->starts_at) : '…' }} → {{ $voucher->ends_at ? PanelFormat::date($voucher->ends_at) : '…' }}
                                @else
                                    <span class="muted">{{ __('panel.vouchers.always') }}</span>
                                @endif
                            </td>
                            <td class="col-num">
                                {{ $used->orders ?? 0 }}@if ($voucher->usage_limit) / {{ $voucher->usage_limit }}@endif
                                @if ($used)<span class="cell-sub">− {{ PanelFormat::money((int) $used->discount) }}</span>@endif
                            </td>
                            <td>
                                @if (! $voucher->is_active)
                                    <x-panel.pill tone="grey">{{ __('panel.common.inactive') }}</x-panel.pill>
                                @elseif ($voucher->ends_at && $voucher->ends_at->isPast())
                                    <x-panel.pill tone="red">{{ __('panel.vouchers.filters.expired') }}</x-panel.pill>
                                @elseif ($voucher->starts_at && $voucher->starts_at->isFuture())
                                    <x-panel.pill tone="blue">{{ __('panel.vouchers.filters.scheduled') }}</x-panel.pill>
                                @else
                                    <x-panel.pill tone="green">{{ __('panel.vouchers.filters.live') }}</x-panel.pill>
                                @endif
                                @if ($voucher->is_public)<span class="cell-sub">{{ __('panel.vouchers.announced') }}</span>@endif
                            </td>
                            <td class="col-actions"><a class="btn-p btn-p--ghost btn-p--sm" href="{{ route('panel.vouchers.edit', $voucher) }}">{{ __('panel.common.edit') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-panel.pager :paginator="$vouchers"/>
        @endif
    </section>
@endsection
