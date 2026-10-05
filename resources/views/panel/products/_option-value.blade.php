@php $field = "options[$i][values][$v]"; @endphp

<div class="repeat__row" data-repeat-row style="grid-template-columns: repeat(2, minmax(0, 1fr)) 140px auto; align-items: end">
    <input type="hidden" name="{{ $field }}[id]" value="{{ $value['id'] ?? '' }}">
    <x-panel.input :name="$field.'[name_ar]'" :label="__('panel.products.valueNameAr')" :value="$value['name_ar'] ?? ''" required maxlength="190" lang="ar" dir="rtl"/>
    <x-panel.input :name="$field.'[name_en]'" :label="__('panel.products.valueNameEn')" :value="$value['name_en'] ?? ''" required maxlength="190" lang="en" dir="ltr"/>
    <x-panel.input :name="$field.'[price]'" :label="__('panel.products.extraPrice')" :value="$value['price'] ?? '0.000'" inputmode="decimal" ltr/>
    <div class="row" style="align-self:end;padding-block-end:6px">
        <label class="check"><input type="checkbox" name="{{ $field }}[is_active]" value="1" @checked($value['is_active'] ?? true)> {{ __('panel.common.active') }}</label>
        <button class="icon-btn" type="button" data-repeat-remove title="{{ __('panel.common.remove') }}"><x-panel.icon name="trash" :size="18"/><span class="visually-hidden">{{ __('panel.common.remove') }}</span></button>
    </div>
</div>
