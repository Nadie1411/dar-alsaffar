@extends('panel.layout')

@php
    use App\Support\Media;
    use App\Support\PanelFormat;

    $editing = $product->exists;
    $discountType = old('discount_type', $product->discount_type ?: 'none');
@endphp

@section('title', $editing ? $product->localized('name') : __('panel.products.add'))

@section('content')
    <x-panel.page-head :title="$editing ? $product->localized('name') : __('panel.products.add')"
                       :back="route('panel.products.index')" :backLabel="__('panel.modules.products')">
        @if ($editing)
            <a class="btn-p btn-p--ghost" href="{{ PanelFormat::storeUrl('products/'.$product->slug) }}" target="_blank" rel="noopener">{{ __('panel.products.viewOnStore') }}</a>
        @endif
    </x-panel.page-head>

    <form class="grid grid--main" method="POST" enctype="multipart/form-data"
          action="{{ $editing ? route('panel.products.update', $product) : route('panel.products.store') }}">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="stack">
            {{-- ---- basics ------------------------------------------------ --}}
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.products.basics') }}</h2></div>
                <div class="card__body form-grid">
                    <x-panel.input name="name_ar" :label="__('panel.products.nameAr')" :value="old('name_ar', $product->name_ar)" required maxlength="255" lang="ar" dir="rtl"/>
                    <x-panel.input name="name_en" :label="__('panel.products.nameEn')" :value="old('name_en', $product->name_en)" required maxlength="255" lang="en" dir="ltr"/>
                    <x-panel.textarea name="description_ar" :label="__('panel.products.descriptionAr')" :value="old('description_ar', $product->description_ar)" rows="6" lang="ar" dir="rtl"/>
                    <x-panel.textarea name="description_en" :label="__('panel.products.descriptionEn')" :value="old('description_en', $product->description_en)" rows="6" lang="en" dir="ltr"/>
                    <p class="field-p__hint span-2">{{ __('panel.products.descriptionHint') }}</p>
                    <x-panel.input name="sku" :label="__('panel.products.sku')" :value="old('sku', $product->sku)" maxlength="100" ltr/>
                    <x-panel.input name="slug" :label="__('panel.categories.slug')" :value="old('slug', $product->slug)" maxlength="190" ltr
                                   :hint="$editing ? __('panel.products.slugEditHint') : __('panel.categories.slugHint')"/>
                    <x-panel.input name="tags" :label="__('panel.products.tags')" :value="$tags" maxlength="500" :hint="__('panel.products.tagsHint')"/>
                    <x-panel.input name="video" type="url" :label="__('panel.products.video')" :value="old('video', $product->video)" maxlength="500" ltr :hint="__('panel.products.videoHint')"/>
                </div>
            </section>

            {{-- ---- pictures ---------------------------------------------- --}}
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.products.pictures') }}</h2></div>
                <div class="card__body stack" style="--gap:18px">
                    <x-panel.image-field name="main_image" :label="__('panel.products.mainPicture')" :current="$product->main_image" removeName="remove_main_image" :hint="__('panel.common.imageHint')"/>

                    <div class="field-p">
                        <span class="field-p__label">{{ __('panel.products.gallery') }}</span>
                        <div class="thumbs" id="gallery-preview">
                            @foreach ($product->images as $image)
                                @if ($url = Media::url($image->path))
                                    <div class="thumbs__item">
                                        <img src="{{ $url }}" alt="" loading="lazy">
                                        <label><input type="checkbox" name="remove_gallery[]" value="{{ $image->id }}"> {{ __('panel.common.remove') }}</label>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        <input class="in" type="file" name="gallery[]" multiple accept="image/jpeg,image/png,image/webp" data-preview="#gallery-preview">
                        @error('gallery.*')<span class="field-p__error">{{ $message }}</span>@enderror
                        @error('gallery')<span class="field-p__error">{{ $message }}</span>@enderror
                        <span class="field-p__hint">{{ __('panel.products.galleryHint') }}</span>
                    </div>
                </div>
            </section>

            {{-- ---- pricing ----------------------------------------------- --}}
            <section class="card" data-switch-scope>
                <div class="card__head"><h2>{{ __('panel.products.pricing') }}</h2></div>
                <div class="card__body form-grid">
                    <x-panel.input name="price" :label="__('panel.products.price')" :value="$price" required inputmode="decimal" ltr :suffix="__('panel.common.currency')"
                                   :hint="__('panel.products.priceHint')"/>
                    <x-panel.select name="discount_type" :label="__('panel.products.discount')" data-switch :selected="$discountType"
                                    :options="['none' => __('panel.products.typeNone'), 'fixed' => __('panel.products.typeFixed'), 'percentage' => __('panel.products.typePercentage')]"/>
                    <div data-when="fixed" @if ($discountType !== 'fixed') hidden @endif>
                        <x-panel.input name="discount_amount" :label="__('panel.products.discountAmount')" :value="$discountAmount" inputmode="decimal" ltr :suffix="__('panel.common.currency')"/>
                    </div>
                    <div data-when="percentage" @if ($discountType !== 'percentage') hidden @endif>
                        <x-panel.input name="discount_percent" :label="__('panel.products.discountPercent')" :value="$discountPercent" inputmode="decimal" ltr suffix="%"/>
                    </div>
                    <div class="span-2 form-grid" data-when="fixed percentage" @if ($discountType === 'none') hidden @endif>
                        <x-panel.input name="discount_starts_at" type="datetime-local" :label="__('panel.products.discountStarts')" :value="$startsAt" ltr :hint="__('panel.products.discountDatesHint')"/>
                        <x-panel.input name="discount_ends_at" type="datetime-local" :label="__('panel.products.discountEnds')" :value="$endsAt" ltr/>
                    </div>
                </div>
            </section>

            {{-- ---- inventory --------------------------------------------- --}}
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.products.inventory') }}</h2></div>
                <div class="card__body form-grid">
                    <div class="span-2">
                        <x-panel.switch name="track_stock" :label="__('panel.products.trackStock')" :checked="old('track_stock', $product->track_stock)" :hint="__('panel.products.trackStockHint')"/>
                    </div>
                    <x-panel.input name="stock" type="number" min="0" :label="__('panel.products.stockOnHand')" :value="old('stock', $product->stock)" ltr/>
                    <x-panel.input name="low_stock_threshold" type="number" min="0" :label="__('panel.products.lowThreshold')" :value="old('low_stock_threshold', $product->low_stock_threshold)" ltr :hint="__('panel.products.lowThresholdHint')"/>
                    <x-panel.input name="max_per_order" type="number" min="1" max="999" :label="__('panel.products.maxPerOrder')" :value="old('max_per_order', $product->max_per_order)" ltr :hint="__('panel.products.maxPerOrderHint')"/>
                </div>
            </section>

            {{-- ---- option groups ----------------------------------------- --}}
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.products.options') }}</h2></div>
                <div class="card__body">
                    <p class="muted small" style="margin-block-end:14px">{{ __('panel.products.optionsHint') }}</p>
                    <div class="repeat" data-repeat data-next="{{ count($optionRows) }}">
                        <div class="repeat" data-repeat-rows>
                            @foreach ($optionRows as $index => $row)
                                @include('panel.products._option-group', ['i' => $index, 'row' => $row])
                            @endforeach
                        </div>
                        <template data-repeat-template="group" data-token="__G__">
                            @include('panel.products._option-group', ['i' => '__G__', 'row' => []])
                        </template>
                        <div><button class="btn-p btn-p--ghost btn-p--sm" type="button" data-repeat-add="group"><x-panel.icon name="plus" :size="16"/>{{ __('panel.products.addOption') }}</button></div>
                    </div>
                </div>
            </section>

            {{-- ---- quantity tiers ---------------------------------------- --}}
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.products.tiers') }}</h2></div>
                <div class="card__body">
                    <p class="muted small" style="margin-block-end:14px">{{ __('panel.products.tiersHint') }}</p>
                    <div class="repeat" data-repeat data-next="{{ count($tierRows) }}">
                        <div class="repeat" data-repeat-rows>
                            @foreach ($tierRows as $index => $row)
                                @include('panel.products._tier', ['i' => $index, 'row' => $row])
                            @endforeach
                        </div>
                        <template data-repeat-template="tier" data-token="__T__">
                            @include('panel.products._tier', ['i' => '__T__', 'row' => []])
                        </template>
                        <div><button class="btn-p btn-p--ghost btn-p--sm" type="button" data-repeat-add="tier"><x-panel.icon name="plus" :size="16"/>{{ __('panel.products.addTier') }}</button></div>
                    </div>
                </div>
            </section>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card__body stack" style="--gap:10px">
                    <button class="btn-p btn-p--block" type="submit">{{ __('panel.common.saveChanges') }}</button>
                    @if ($editing)
                        <button class="btn-p btn-p--ghost btn-p--block" type="submit" form="duplicate-product">{{ __('panel.products.duplicate') }}</button>
                        <button class="btn-p btn-p--danger btn-p--block" type="submit" form="delete-product" data-danger
                                data-confirm="{{ __('panel.products.confirmDelete') }}">{{ __('panel.common.delete') }}</button>
                    @endif
                </div>
            </div>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.categories.visibility') }}</h2></div>
                <div class="card__body stack" style="--gap:14px">
                    <x-panel.switch name="is_active" :label="__('panel.products.showOnStore')" :checked="old('is_active', $product->is_active)"/>
                    <x-panel.switch name="is_featured" :label="__('panel.products.featured')" :checked="old('is_featured', $product->is_featured)"/>
                    <x-panel.switch name="is_new" :label="__('panel.products.isNew')" :checked="old('is_new', $product->is_new)"/>
                    <x-panel.switch name="is_popular" :label="__('panel.products.isPopular')" :checked="old('is_popular', $product->is_popular)"/>
                    <x-panel.switch name="cod_enabled" :label="__('panel.products.codEnabled')" :checked="old('cod_enabled', $product->cod_enabled)" :hint="__('panel.products.codHint')"/>
                    <x-panel.input name="sort_order" type="number" min="0" :label="__('panel.categories.order')" :value="old('sort_order', $product->sort_order)" ltr :hint="__('panel.categories.orderHint')"/>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.products.categories') }}</h2></div>
                <div class="card__body stack" style="--gap:8px">
                    @forelse ($categories as $category)
                        <label class="check" style="padding-inline-start: {{ $category->parent_id ? 22 : 0 }}px">
                            <input type="checkbox" name="categories[]" value="{{ $category->id }}" @checked(in_array($category->id, $selectedCategories, true))>
                            {{ $category->localized('name') }}
                        </label>
                    @empty
                        <p class="muted small">{{ __('panel.products.noCategories') }}</p>
                    @endforelse
                    @error('categories.*')<span class="field-p__error">{{ $message }}</span>@enderror
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.products.related') }}</h2></div>
                <div class="card__body stack" style="--gap:10px">
                    <input class="in" type="search" placeholder="{{ __('panel.common.search') }}" data-filter="#related-list" aria-label="{{ __('panel.common.search') }}">
                    <div class="stack" id="related-list" style="--gap:8px; max-block-size: 260px; overflow-y: auto">
                        @foreach ($others as $other)
                            <label class="check" data-filter-item>
                                <input type="checkbox" name="related[]" value="{{ $other->id }}" @checked(in_array($other->id, $selectedRelated, true))>
                                {{ app()->getLocale() === 'ar' ? ($other->name_ar ?: $other->name_en) : ($other->name_en ?: $other->name_ar) }}
                            </label>
                        @endforeach
                    </div>
                    <span class="field-p__hint">{{ __('panel.products.relatedHint') }}</span>
                </div>
            </section>
        </div>
    </form>

    @if ($editing)
        <form id="duplicate-product" method="POST" action="{{ route('panel.products.duplicate', $product) }}" hidden>@csrf</form>
        <form id="delete-product" method="POST" action="{{ route('panel.products.destroy', $product) }}" hidden>@csrf @method('DELETE')</form>
    @endif
@endsection
