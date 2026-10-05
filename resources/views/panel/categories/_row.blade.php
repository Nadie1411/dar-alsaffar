@php use App\Support\Media; @endphp

<tr>
    <td>
        <div class="row" style="flex-wrap:nowrap; padding-inline-start: {{ $depth * 28 }}px">
            @if ($image = Media::url($category->image))
                <img class="thumb" src="{{ $image }}" alt="" loading="lazy">
            @endif
            <div>
                <a class="cell-main" href="{{ route('panel.categories.edit', $category) }}">{{ $category->localized('name') }}</a>
                <span class="cell-sub">{{ app()->getLocale() === 'ar' ? $category->name_en : $category->name_ar }}</span>
            </div>
            @if ($category->is_featured)<x-panel.pill tone="amber" plain>{{ __('panel.categories.featured') }}</x-panel.pill>@endif
        </div>
    </td>
    <td><span class="ltr muted">{{ $category->slug }}</span></td>
    <td class="col-num">{{ $category->products_count }}</td>
    <td class="col-num">{{ $category->sort_order }}</td>
    <td><x-panel.pill :tone="$category->is_active ? 'green' : 'grey'">{{ $category->is_active ? __('panel.common.active') : __('panel.common.inactive') }}</x-panel.pill></td>
    <td class="col-actions">
        <a class="btn-p btn-p--ghost btn-p--sm" href="{{ route('panel.categories.edit', $category) }}">{{ __('panel.common.edit') }}</a>
    </td>
</tr>
