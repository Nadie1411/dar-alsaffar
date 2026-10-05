@php
    $field = "tiers[$i]";
    $type = $row['discount_type'] ?? 'percentage';
@endphp

<div class="repeat__row" data-repeat-row data-switch-scope>
    <div class="repeat__row-head">
        <strong>{{ __('panel.products.tier') }}</strong>
        <button class="btn-p btn-p--ghost btn-p--sm" type="button" data-repeat-remove>{{ __('panel.common.remove') }}</button>
    </div>

    <input type="hidden" name="{{ $field }}[id]" value="{{ $row['id'] ?? '' }}">

    <div class="form-grid form-grid--3">
        <x-panel.input :name="$field.'[min_quantity]'" type="number" min="1" max="9999" :label="__('panel.products.tierMinimum')" :value="$row['min_quantity'] ?? ''" required ltr/>
        <x-panel.select :name="$field.'[discount_type]'" :label="__('panel.products.discountType')" data-switch
                        :options="['none' => __('panel.products.typeNone'), 'percentage' => __('panel.products.typePercentage'), 'fixed' => __('panel.products.typeFixed')]" :selected="$type"/>
        <div data-when="fixed" @if ($type !== 'fixed') hidden @endif>
            <x-panel.input :name="$field.'[discount_amount]'" :label="__('panel.products.discountAmount')" :value="$row['discount_amount'] ?? ''" inputmode="decimal" ltr :suffix="__('panel.common.currency')"/>
        </div>
        <div data-when="percentage" @if ($type !== 'percentage') hidden @endif>
            <x-panel.input :name="$field.'[discount_percent]'" :label="__('panel.products.discountPercent')" :value="$row['discount_percent'] ?? ''" inputmode="decimal" ltr suffix="%"/>
        </div>
        <x-panel.input :name="$field.'[label_ar]'" :label="__('panel.products.tierLabelAr')" :value="$row['label_ar'] ?? ''" maxlength="190" lang="ar" dir="rtl"/>
        <x-panel.input :name="$field.'[label_en]'" :label="__('panel.products.tierLabelEn')" :value="$row['label_en'] ?? ''" maxlength="190" lang="en" dir="ltr"/>
    </div>
    <label class="check"><input type="checkbox" name="{{ $field }}[free_delivery]" value="1" @checked($row['free_delivery'] ?? false)> {{ __('panel.products.tierFreeDelivery') }}</label>
</div>
