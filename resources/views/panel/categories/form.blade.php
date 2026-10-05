@extends('panel.layout')

@php $editing = $category->exists; @endphp

@section('title', $editing ? $category->localized('name') : __('panel.categories.add'))

@section('content')
    <x-panel.page-head :title="$editing ? __('panel.categories.edit') : __('panel.categories.add')"
                       :back="route('panel.categories.index')" :backLabel="__('panel.modules.categories')"/>

    <form class="grid grid--main" method="POST" enctype="multipart/form-data"
          action="{{ $editing ? route('panel.categories.update', $category) : route('panel.categories.store') }}">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="stack">
            <section class="card">
                <div class="card__body form-grid">
                    <x-panel.input name="name_ar" :label="__('panel.categories.nameAr')" :value="old('name_ar', $category->name_ar)" required maxlength="190" lang="ar" dir="rtl"/>
                    <x-panel.input name="name_en" :label="__('panel.categories.nameEn')" :value="old('name_en', $category->name_en)" required maxlength="190" lang="en" dir="ltr"/>
                    <x-panel.input name="slug" :label="__('panel.categories.slug')" :value="old('slug', $category->slug)" ltr maxlength="190"
                                   :hint="$editing ? __('panel.categories.slugEditHint') : __('panel.categories.slugHint')"/>
                    <x-panel.select name="parent_id" :label="__('panel.categories.parent')" :options="$parents->mapWithKeys(fn ($parent) => [$parent->id => $parent->localized('name')])->all()"
                                    :selected="old('parent_id', $category->parent_id)" :blank="__('panel.categories.topLevel')"/>
                    <x-panel.input name="sort_order" type="number" :label="__('panel.categories.order')" :value="old('sort_order', $category->sort_order)" min="0" ltr
                                   :hint="__('panel.categories.orderHint')"/>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.categories.picture') }}</h2></div>
                <div class="card__body">
                    <x-panel.image-field name="image" :label="__('panel.categories.picture')" :current="$category->image" removeName="remove_image"
                                         :hint="__('panel.common.imageHint')"/>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.categories.visibility') }}</h2></div>
                <div class="card__body stack" style="--gap:14px">
                    <x-panel.switch name="is_active" :label="__('panel.categories.showOnStore')" :checked="old('is_active', $category->is_active)"/>
                    <x-panel.switch name="is_featured" :label="__('panel.categories.featuredOnHome')" :checked="old('is_featured', $category->is_featured)"/>
                </div>
            </section>

            <div class="row">
                <button class="btn-p grow" type="submit">{{ __('panel.common.saveChanges') }}</button>
                <a class="btn-p btn-p--ghost" href="{{ route('panel.categories.index') }}">{{ __('panel.common.cancel') }}</a>
            </div>

            @if ($editing)
                <button class="btn-p btn-p--danger btn-p--block" type="submit" form="delete-category" data-danger
                        data-confirm="{{ __('panel.categories.confirmDelete') }}">{{ __('panel.common.delete') }}</button>
            @endif
        </div>
    </form>

    @if ($editing)
        <form id="delete-category" method="POST" action="{{ route('panel.categories.destroy', $category) }}" hidden>
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
