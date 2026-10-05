@extends('panel.layout')

@section('title', __('panel.modules.staff'))

@section('content')
    @php use App\Support\PanelFormat; @endphp

    <x-panel.page-head :title="__('panel.modules.staff')" :sub="__('panel.staff.sub')">
        <a class="btn-p" href="{{ route('panel.staff.create') }}"><x-panel.icon name="plus" :size="18"/>{{ __('panel.staff.add') }}</a>
    </x-panel.page-head>

    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('panel.common.name') }}</th>
                    <th>{{ __('panel.staff.role') }}</th>
                    <th>{{ __('panel.staff.lastLogin') }}</th>
                    <th>{{ __('panel.common.status') }}</th>
                    <th class="col-actions">{{ __('panel.common.actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($members as $member)
                    <tr>
                        <td>
                            <a class="cell-main" href="{{ route('panel.staff.edit', $member) }}">{{ $member->name }}</a>
                            <span class="cell-sub"><span class="ltr">{{ $member->email }}</span></span>
                        </td>
                        <td>
                            <x-panel.pill :tone="$member->isOwner() ? 'amber' : 'blue'" plain>{{ $member->role->label() }}</x-panel.pill>
                        </td>
                        <td>{{ $member->last_login_at ? PanelFormat::dateTime($member->last_login_at) : __('panel.staff.never') }}</td>
                        <td><x-panel.pill :tone="$member->is_active ? 'green' : 'grey'">{{ $member->is_active ? __('panel.common.active') : __('panel.common.inactive') }}</x-panel.pill></td>
                        <td class="col-actions"><a class="btn-p btn-p--ghost btn-p--sm" href="{{ route('panel.staff.edit', $member) }}">{{ __('panel.common.edit') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>{{ __('panel.staff.rolesTitle') }}</h2></div>
        <div class="card__body stack" style="--gap:14px">
            @foreach (\App\Enums\AdminRole::cases() as $role)
                <div>
                    <strong>{{ $role->label() }}</strong>
                    <p class="muted small">{{ collect($role->modules())->map(fn ($module) => $module->label())->implode('، ') }}</p>
                </div>
            @endforeach
        </div>
    </section>
@endsection
