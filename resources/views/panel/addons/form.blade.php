@extends('panel.layout')

@php $editing = $addon->exists; @endphp

@section('title', $editing ? $addon->localized('name') : __('panel.addons.add'))

@section('content')
    <x-panel.page-head :title="$editing ? __('panel.addons.edit') : __('panel.addons.add')"
                       :back="route('panel.addons.index')" :backLabel="__('panel.modules.addons')"/>

    <form class="grid grid--main" method="POST" enctype="multipart/form-data"
          action="{{ $editing ? route('panel.addons.update', $addon) : route('panel.addons.store') }}">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="stack">
            <section class="card">
                <div class="card__body form-grid">
                    <x-panel.input name="name_ar" :label="__('panel.categories.nameAr')" :value="old('name_ar', $addon->name_ar)" required maxlength="190" lang="ar" dir="rtl"/>
                    <x-panel.input name="name_en" :label="__('panel.categories.nameEn')" :value="old('name_en', $addon->name_en)" required maxlength="190" lang="en" dir="ltr"/>
                    <x-panel.input name="description_ar" :label="__('panel.addons.descriptionAr')" :value="old('description_ar', $addon->description_ar)" maxlength="500" lang="ar" dir="rtl"/>
                    <x-panel.input name="description_en" :label="__('panel.addons.descriptionEn')" :value="old('description_en', $addon->description_en)" maxlength="500" lang="en" dir="ltr"/>
                    <x-panel.input name="price" :label="__('panel.products.price')" :value="old('price', $price)" required inputmode="decimal" ltr :suffix="__('panel.common.currency')" :hint="__('panel.addons.priceHint')"/>
                    <x-panel.input name="sort_order" type="number" min="0" :label="__('panel.categories.order')" :value="old('sort_order', $addon->sort_order)" ltr :hint="__('panel.categories.orderHint')"/>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.categories.picture') }}</h2></div>
                <div class="card__body">
                    <x-panel.image-field name="image" :label="__('panel.categories.picture')" :current="$addon->image" removeName="remove_image" :hint="__('panel.common.imageHint')"/>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.categories.visibility') }}</h2></div>
                <div class="card__body">
                    <x-panel.switch name="is_active" :label="__('panel.addons.offer')" :checked="old('is_active', $addon->is_active)"/>
                </div>
            </section>

            <div class="row">
                <button class="btn-p grow" type="submit">{{ __('panel.common.saveChanges') }}</button>
                <a class="btn-p btn-p--ghost" href="{{ route('panel.addons.index') }}">{{ __('panel.common.cancel') }}</a>
            </div>

            @if ($editing)
                <button class="btn-p btn-p--danger btn-p--block" type="submit" form="delete-addon" data-danger
                        data-confirm="{{ __('panel.addons.confirmDelete') }}">{{ __('panel.common.delete') }}</button>
            @endif
        </div>
    </form>

    @if ($editing)
        <form id="delete-addon" method="POST" action="{{ route('panel.addons.destroy', $addon) }}" hidden>@csrf @method('DELETE')</form>
    @endif
@endsection
