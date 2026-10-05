@extends('panel.layout')

@section('title', __('panel.modules.products'))

@section('content')
    @php
        use App\Support\Media;
        use App\Support\PanelFormat;
    @endphp

    <x-panel.page-head :title="__('panel.modules.products')" :sub="trans_choice('panel.products.count', $total)">
        <a class="btn-p" href="{{ route('panel.products.create') }}"><x-panel.icon name="plus" :size="18"/>{{ __('panel.products.add') }}</a>
    </x-panel.page-head>

    <section class="card">
        <form class="toolbar" method="GET" action="{{ route('panel.products.index') }}">
            <div class="search">
                <x-panel.icon name="search" :size="18"/>
                <input class="in" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('panel.products.searchHint') }}" aria-label="{{ __('panel.common.search') }}">
            </div>
            <select class="sel" name="category" aria-label="{{ __('panel.products.category') }}" data-autosubmit>
                <option value="">{{ __('panel.products.allCategories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>{{ $category->parent_id ? '— ' : '' }}{{ $category->localized('name') }}</option>
                @endforeach
            </select>
            <select class="sel" name="status" aria-label="{{ __('panel.common.status') }}" data-autosubmit>
                <option value="">{{ __('panel.products.anyStatus') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('panel.common.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('panel.common.inactive') }}</option>
            </select>
            <select class="sel" name="stock" aria-label="{{ __('panel.products.stock') }}" data-autosubmit>
                <option value="">{{ __('panel.products.anyStock') }}</option>
                <option value="low" @selected($filters['stock'] === 'low')>{{ __('panel.products.stockLow') }}</option>
                <option value="out" @selected($filters['stock'] === 'out')>{{ __('panel.products.stockOut') }}</option>
                <option value="untracked" @selected($filters['stock'] === 'untracked')>{{ __('panel.products.stockUntracked') }}</option>
            </select>
            <button class="btn-p btn-p--sm" type="submit">{{ __('panel.common.filter') }}</button>
            @if (array_filter($filters) !== [])
                <a class="link-p small" href="{{ route('panel.products.index') }}">{{ __('panel.common.clearFilters') }}</a>
            @endif
        </form>

        @if ($products->isEmpty())
            <x-panel.empty icon="products" :title="__('panel.common.noResults')" :text="__('panel.common.noResultsHint')"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>{{ __('panel.orders.product') }}</th>
                        <th class="col-num">{{ __('panel.products.price') }}</th>
                        <th>{{ __('panel.products.stock') }}</th>
                        <th>{{ __('panel.products.categories') }}</th>
                        <th>{{ __('panel.common.status') }}</th>
                        <th class="col-actions">{{ __('panel.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($products as $product)
                        <tr>
                            <td>
                                <div class="row" style="flex-wrap:nowrap">
                                    @if ($image = Media::url($product->main_image))
                                        <img class="thumb" src="{{ $image }}" alt="" loading="lazy">
                                    @else
                                        <span class="thumb" aria-hidden="true"></span>
                                    @endif
                                    <div>
                                        <a class="cell-main" href="{{ route('panel.products.edit', $product) }}">{{ $product->localized('name') }}</a>
                                        <span class="cell-sub">
                                            @if ($product->sku)<span class="ltr">{{ $product->sku }}</span>@endif
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="col-num">
                                @if ($product->discountIsActive())
                                    {{ PanelFormat::amount($product->priceFils()) }}
                                    <span class="cell-sub"><s>{{ PanelFormat::amount($product->sell_price_fils) }}</s></span>
                                @else
                                    {{ PanelFormat::amount($product->sell_price_fils) }}
                                @endif
                            </td>
                            <td>
                                @if (! $product->track_stock)
                                    <span class="muted">{{ __('panel.products.unlimited') }}</span>
                                @elseif ($product->stock <= 0)
                                    <x-panel.pill tone="red">{{ __('panel.products.outOfStock') }}</x-panel.pill>
                                @elseif ($product->isLowStock())
                                    <x-panel.pill tone="amber">{{ __('panel.products.leftCount', ['count' => $product->stock]) }}</x-panel.pill>
                                @else
                                    {{ $product->stock }}
                                @endif
                            </td>
                            <td class="small muted">{{ $product->categories->map(fn ($category) => $category->localized('name'))->implode('، ') ?: '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('panel.products.toggle', $product) }}">
                                    @csrf
                                    <button class="pill {{ $product->is_active ? 'pill--green' : 'pill--grey' }}" type="submit" style="border:0;cursor:pointer"
                                            title="{{ __('panel.products.toggleHint') }}">
                                        {{ $product->is_active ? __('panel.common.active') : __('panel.common.inactive') }}
                                    </button>
                                </form>
                            </td>
                            <td class="col-actions">
                                <a class="btn-p btn-p--ghost btn-p--sm" href="{{ route('panel.products.edit', $product) }}">{{ __('panel.common.edit') }}</a>
                                <a class="btn-p btn-p--ghost btn-p--sm" href="{{ PanelFormat::storeUrl('products/'.$product->slug) }}" target="_blank" rel="noopener">{{ __('panel.products.viewOnStore') }}</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-panel.pager :paginator="$products"/>
        @endif
    </section>
@endsection
