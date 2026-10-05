@extends('panel.layout')

@section('title', __('panel.modules.addons'))

@section('content')
    @php
        use App\Support\Media;
        use App\Support\PanelFormat;
    @endphp

    <x-panel.page-head :title="__('panel.modules.addons')" :sub="__('panel.addons.sub')">
        <a class="btn-p" href="{{ route('panel.addons.create') }}"><x-panel.icon name="plus" :size="18"/>{{ __('panel.addons.add') }}</a>
    </x-panel.page-head>

    <section class="card">
        @if ($addons->isEmpty())
            <x-panel.empty icon="addons" :title="__('panel.addons.none')" :text="__('panel.addons.noneHint')"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('panel.common.name') }}</th>
                        <th class="col-num">{{ __('panel.products.price') }}</th>
                        <th class="col-num">{{ __('panel.categories.order') }}</th>
                        <th>{{ __('panel.common.status') }}</th>
                        <th class="col-actions">{{ __('panel.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($addons as $addon)
                        <tr>
                            <td>
                                <div class="row" style="flex-wrap:nowrap">
                                    @if ($image = Media::url($addon->image))<img class="thumb" src="{{ $image }}" alt="" loading="lazy">@endif
                                    <div>
                                        <a class="cell-main" href="{{ route('panel.addons.edit', $addon) }}">{{ $addon->localized('name') }}</a>
                                        @if ($addon->localized('description'))<span class="cell-sub">{{ $addon->localized('description') }}</span>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="col-num">{{ PanelFormat::money($addon->price_fils) }}</td>
                            <td class="col-num">{{ $addon->sort_order }}</td>
                            <td><x-panel.pill :tone="$addon->is_active ? 'green' : 'grey'">{{ $addon->is_active ? __('panel.common.active') : __('panel.common.inactive') }}</x-panel.pill></td>
                            <td class="col-actions"><a class="btn-p btn-p--ghost btn-p--sm" href="{{ route('panel.addons.edit', $addon) }}">{{ __('panel.common.edit') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
