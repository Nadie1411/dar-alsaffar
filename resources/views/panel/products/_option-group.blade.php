@php
    $field = "options[$i]";
    $values = $row['values'] ?? [[]];
    $layout = $row['layout'] ?? 'radio';
@endphp

<div class="repeat__row" data-repeat-row data-switch-scope>
    <div class="repeat__row-head">
        <strong>{{ __('panel.products.optionGroup') }}</strong>
        <button class="btn-p btn-p--ghost btn-p--sm" type="button" data-repeat-remove>{{ __('panel.common.remove') }}</button>
    </div>

    <input type="hidden" name="{{ $field }}[id]" value="{{ $row['id'] ?? '' }}">

    <div class="form-grid">
        <x-panel.input :name="$field.'[name_ar]'" :label="__('panel.products.optionNameAr')" :value="$row['name_ar'] ?? ''" required maxlength="190" lang="ar" dir="rtl"/>
        <x-panel.input :name="$field.'[name_en]'" :label="__('panel.products.optionNameEn')" :value="$row['name_en'] ?? ''" required maxlength="190" lang="en" dir="ltr"/>
        <x-panel.select :name="$field.'[layout]'" :label="__('panel.products.layout')" data-switch
                        :options="['radio' => __('panel.products.layoutRadio'), 'checkbox' => __('panel.products.layoutCheckbox')]" :selected="$layout"/>
        <div class="row">
            <label class="check"><input type="checkbox" name="{{ $field }}[is_required]" value="1" @checked($row['is_required'] ?? false)> {{ __('panel.products.required') }}</label>
            <label class="check"><input type="checkbox" name="{{ $field }}[is_active]" value="1" @checked($row['is_active'] ?? true)> {{ __('panel.common.active') }}</label>
        </div>
        <div class="span-2 form-grid" data-when="checkbox" @if ($layout !== 'checkbox') hidden @endif>
            <x-panel.input :name="$field.'[min_choices]'" type="number" min="0" max="99" :label="__('panel.products.minChoices')" :value="$row['min_choices'] ?? 0" ltr/>
            <x-panel.input :name="$field.'[max_choices]'" type="number" min="0" max="99" :label="__('panel.products.maxChoices')" :value="$row['max_choices'] ?? 0" ltr :hint="__('panel.products.maxChoicesHint')"/>
        </div>
    </div>

    <div class="repeat" data-repeat data-next="{{ count($values) }}">
        <p class="field-p__label">{{ __('panel.products.optionValues') }}</p>
        <div class="repeat" data-repeat-rows>
            @foreach ($values as $valueIndex => $value)
                @include('panel.products._option-value', ['i' => $i, 'v' => $valueIndex, 'value' => $value])
            @endforeach
        </div>
        <template data-repeat-template="value" data-token="__V__">
            @include('panel.products._option-value', ['i' => $i, 'v' => '__V__', 'value' => []])
        </template>
        <div><button class="btn-p btn-p--ghost btn-p--sm" type="button" data-repeat-add="value"><x-panel.icon name="plus" :size="16"/>{{ __('panel.products.addValue') }}</button></div>
    </div>
</div>
