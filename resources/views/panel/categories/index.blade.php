@extends('panel.layout')

@section('title', __('panel.modules.categories'))

@section('content')
    @php use App\Support\Media; @endphp

    <x-panel.page-head :title="__('panel.modules.categories')" :sub="__('panel.categories.sub')">
        <a class="btn-p" href="{{ route('panel.categories.create') }}"><x-panel.icon name="plus" :size="18"/>{{ __('panel.categories.add') }}</a>
    </x-panel.page-head>

    <section class="card">
        @if ($tree->isEmpty())
            <x-panel.empty icon="categories" :title="__('panel.categories.none')" :text="__('panel.categories.noneHint')"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('panel.common.name') }}</th>
                        <th>{{ __('panel.categories.slug') }}</th>
                        <th class="col-num">{{ __('panel.categories.products') }}</th>
                        <th class="col-num">{{ __('panel.categories.order') }}</th>
                        <th>{{ __('panel.common.status') }}</th>
                        <th class="col-actions">{{ __('panel.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($tree as $category)
                        @include('panel.categories._row', ['category' => $category, 'depth' => 0])
                        @foreach ($children->get($category->id, collect()) as $child)
                            @include('panel.categories._row', ['category' => $child, 'depth' => 1])
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
