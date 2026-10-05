@extends('panel.layout')

@section('title', $city->localized('name'))

@section('content')
    <x-panel.page-head :title="$city->localized('name')" :back="route('panel.delivery.index')" :backLabel="__('panel.modules.delivery')"
                       :sub="__('panel.delivery.cityHint', ['fee' => $defaultFee.' '.__('panel.common.currency')])"/>

    <form class="stack" method="POST" action="{{ route('panel.delivery.cities.update', $city) }}">
        @csrf @method('PUT')

        <section class="card">
            <div class="card__body form-grid">
                <x-panel.input name="name_ar" :label="__('panel.categories.nameAr')" :value="old('name_ar', $city->name_ar)" required maxlength="190" lang="ar" dir="rtl"/>
                <x-panel.input name="name_en" :label="__('panel.categories.nameEn')" :value="old('name_en', $city->name_en)" required maxlength="190" lang="en" dir="ltr"/>
                <div class="span-2"><x-panel.switch name="is_active" :label="__('panel.delivery.deliverHere')" :checked="old('is_active', $city->is_active)"/></div>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>{{ __('panel.delivery.areas') }} ({{ $areas->count() }})</h2></div>
            @if ($areas->isNotEmpty())
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>{{ __('panel.categories.nameAr') }}</th>
                            <th>{{ __('panel.categories.nameEn') }}</th>
                            <th style="min-inline-size:150px">{{ __('panel.delivery.fee') }}</th>
                            <th>{{ __('panel.common.active') }}</th>
                            <th>{{ __('panel.common.remove') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($areas as $area)
                            @php
                                $old = old('areas.'.$area->id);
                                $fee = $old['fee'] ?? ($area->fee_fils !== null ? \App\Support\PanelFormat::amount($area->fee_fils) : '');
                            @endphp
                            <tr>
                                <td><input class="in" name="areas[{{ $area->id }}][name_ar]" value="{{ $old['name_ar'] ?? $area->name_ar }}" required maxlength="190" lang="ar" dir="rtl" aria-label="{{ __('panel.categories.nameAr') }}"></td>
                                <td><input class="in" name="areas[{{ $area->id }}][name_en]" value="{{ $old['name_en'] ?? $area->name_en }}" required maxlength="190" lang="en" dir="ltr" aria-label="{{ __('panel.categories.nameEn') }}"></td>
                                <td>
                                    <input class="in in--ltr" name="areas[{{ $area->id }}][fee]" value="{{ $fee }}" inputmode="decimal" placeholder="{{ $defaultFee }}" aria-label="{{ __('panel.delivery.fee') }}">
                                    @error('areas.'.$area->id.'.fee')<span class="field-p__error">{{ $message }}</span>@enderror
                                </td>
                                <td><input type="checkbox" name="areas[{{ $area->id }}][is_active]" value="1" @checked($old ? ! empty($old['is_active']) : $area->is_active) aria-label="{{ __('panel.common.active') }}"></td>
                                <td><input type="checkbox" name="areas[{{ $area->id }}][remove]" value="1" aria-label="{{ __('panel.common.remove') }}"></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="card__body">
                <p class="field-p__label" style="margin-block-end:10px">{{ __('panel.delivery.addAreas') }}</p>
                <div class="repeat" data-repeat data-next="0">
                    <div class="repeat" data-repeat-rows></div>
                    <template data-repeat-template="area" data-token="__A__">
                        <div class="repeat__row" data-repeat-row style="grid-template-columns: 1fr 1fr 150px auto; align-items: end">
                            <x-panel.input name="new_areas[__A__][name_ar]" :label="__('panel.categories.nameAr')" required maxlength="190" lang="ar" dir="rtl"/>
                            <x-panel.input name="new_areas[__A__][name_en]" :label="__('panel.categories.nameEn')" required maxlength="190" lang="en" dir="ltr"/>
                            <x-panel.input name="new_areas[__A__][fee]" :label="__('panel.delivery.fee')" inputmode="decimal" ltr :placeholder="$defaultFee"/>
                            <button class="icon-btn" type="button" data-repeat-remove title="{{ __('panel.common.remove') }}"><x-panel.icon name="trash" :size="18"/><span class="visually-hidden">{{ __('panel.common.remove') }}</span></button>
                        </div>
                    </template>
                    <div><button class="btn-p btn-p--ghost btn-p--sm" type="button" data-repeat-add="area"><x-panel.icon name="plus" :size="16"/>{{ __('panel.delivery.addArea') }}</button></div>
                </div>
            </div>
            <div class="card__foot row">
                <button class="btn-p" type="submit">{{ __('panel.common.saveChanges') }}</button>
                <a class="btn-p btn-p--ghost" href="{{ route('panel.delivery.index') }}">{{ __('panel.common.cancel') }}</a>
            </div>
        </section>
    </form>

    <section class="card">
        <div class="card__head"><h2>{{ __('panel.delivery.bulkFee') }}</h2></div>
        <form class="card__body row" method="POST" action="{{ route('panel.delivery.cities.fee', $city) }}">
            @csrf
            <div style="flex:1;min-inline-size:200px">
                <x-panel.input name="fee" :label="__('panel.delivery.bulkFeeLabel')" inputmode="decimal" ltr :suffix="__('panel.common.currency')" :hint="__('panel.delivery.bulkFeeHint')"/>
            </div>
            <button class="btn-p btn-p--ghost" type="submit" data-confirm="{{ __('panel.delivery.confirmBulkFee') }}">{{ __('panel.delivery.applyToAll') }}</button>
        </form>
    </section>

    <section class="card">
        <div class="card__body row row--between">
            <p class="muted">{{ __('panel.delivery.deleteCityHint') }}</p>
            <form method="POST" action="{{ route('panel.delivery.cities.destroy', $city) }}">
                @csrf @method('DELETE')
                <button class="btn-p btn-p--danger" type="submit" data-danger data-confirm="{{ __('panel.delivery.confirmDeleteCity') }}">{{ __('panel.delivery.deleteCity') }}</button>
            </form>
        </div>
    </section>
@endsection
