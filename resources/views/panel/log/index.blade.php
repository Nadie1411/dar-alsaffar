@extends('panel.layout')

@section('title', __('panel.modules.log'))

@section('content')
    @php
        use App\Support\PanelFormat;
        use App\Support\PanelNav;

        $subjectRoutes = [
            'order' => ['panel.orders.show', 'orders'],
            'product' => ['panel.products.edit', 'products'],
            'category' => ['panel.categories.edit', 'categories'],
            'voucher' => ['panel.vouchers.edit', 'vouchers'],
            'service_addon' => ['panel.addons.edit', 'addons'],
            'customer' => ['panel.customers.show', 'customers'],
            'user' => ['panel.staff.edit', 'staff'],
        ];
    @endphp

    <x-panel.page-head :title="__('panel.modules.log')" :sub="__('panel.log.sub')"/>

    <section class="card">
        <form class="toolbar" method="GET" action="{{ route('panel.log.index') }}">
            <select class="sel" name="staff" aria-label="{{ __('panel.log.who') }}" data-autosubmit>
                <option value="">{{ __('panel.log.anyone') }}</option>
                @foreach ($members as $member)
                    <option value="{{ $member->id }}" @selected($filters['staff'] === (string) $member->id)>{{ $member->name }}</option>
                @endforeach
            </select>
            <select class="sel" name="area" aria-label="{{ __('panel.log.area') }}" data-autosubmit>
                <option value="">{{ __('panel.log.anyArea') }}</option>
                @foreach ($areas as $area)
                    <option value="{{ $area }}" @selected($filters['area'] === $area)>{{ __('panel.log.areas.'.$area) === 'panel.log.areas.'.$area ? $area : __('panel.log.areas.'.$area) }}</option>
                @endforeach
            </select>
            <label class="row small muted" style="gap:6px">{{ __('panel.orders.from') }}
                <input class="in" style="inline-size:auto" type="date" name="from" value="{{ $filters['from'] }}" data-autosubmit>
            </label>
            <label class="row small muted" style="gap:6px">{{ __('panel.orders.to') }}
                <input class="in" style="inline-size:auto" type="date" name="to" value="{{ $filters['to'] }}" data-autosubmit>
            </label>
            @if (array_filter($filters) !== [])<a class="link-p small" href="{{ route('panel.log.index') }}">{{ __('panel.common.clearFilters') }}</a>@endif
        </form>

        @if ($entries->isEmpty())
            <x-panel.empty icon="log" :title="__('panel.log.none')" :text="__('panel.common.noResultsHint')"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('panel.common.date') }}</th>
                        <th>{{ __('panel.log.who') }}</th>
                        <th>{{ __('panel.log.what') }}</th>
                        <th>{{ __('panel.log.details') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        @php
                            $key = 'panel.log.actions.'.$entry->action;
                            $label = __($key) === $key ? $entry->action : __($key);
                            [$route, $module] = $subjectRoutes[$entry->subject_type] ?? [null, null];
                            $canOpen = $route && $entry->subject_id && ! str_ends_with($entry->action, '.deleted')
                                && auth('staff')->user()->canAccess(\App\Enums\PanelModule::from($module)) && \Illuminate\Support\Facades\Route::has($route);
                        @endphp
                        <tr>
                            <td class="nowrap">{{ PanelFormat::dateTime($entry->created_at) }}</td>
                            <td>{{ $entry->staff?->name ?? __('panel.common.system') }}</td>
                            <td>
                                {{ $label }}
                                @if ($entry->subject_label)
                                    ·
                                    @if ($canOpen)<a class="link-p" href="{{ route($route, $entry->subject_id) }}"><span class="ltr">{{ $entry->subject_label }}</span></a>
                                    @else<span class="ltr">{{ $entry->subject_label }}</span>@endif
                                @endif
                            </td>
                            <td class="small muted">
                                @if ($entry->action === 'order.status_changed' && isset($entry->properties['from'], $entry->properties['to']))
                                    {{ \App\Enums\OrderStatus::tryFrom($entry->properties['from'])?->panelLabel() ?? $entry->properties['from'] }}
                                    →
                                    {{ \App\Enums\OrderStatus::tryFrom($entry->properties['to'])?->panelLabel() ?? $entry->properties['to'] }}
                                @else
                                @foreach ($entry->properties ?? [] as $property => $detail)
                                    <span class="ltr">{{ $property }}: {{ is_scalar($detail) ? (is_bool($detail) ? ($detail ? 'yes' : 'no') : $detail) : json_encode($detail) }}</span>@if (! $loop->last), @endif
                                @endforeach
                                @endif
                                @if ($entry->ip)<span class="cell-sub ltr">{{ $entry->ip }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-panel.pager :paginator="$entries"/>
        @endif
    </section>
@endsection
