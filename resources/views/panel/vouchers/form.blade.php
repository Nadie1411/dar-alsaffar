@extends('panel.layout')

@php
    use App\Models\Voucher;

    $editing = $voucher->exists;
    $type = old('type', $voucher->type);
@endphp

@section('title', $editing ? $voucher->code : __('panel.vouchers.add'))

@section('content')
    <x-panel.page-head :title="$editing ? $voucher->code : __('panel.vouchers.add')"
                       :back="route('panel.vouchers.index')" :backLabel="__('panel.modules.vouchers')"
                       :sub="$editing ? trans_choice('panel.vouchers.redeemed', $redemptions) : null"/>

    <form class="grid grid--main" method="POST" action="{{ $editing ? route('panel.vouchers.update', $voucher) : route('panel.vouchers.store') }}">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="stack">
            <section class="card" data-switch-scope>
                <div class="card__head"><h2>{{ __('panel.vouchers.offer') }}</h2></div>
                <div class="card__body form-grid">
                    <x-panel.input name="code" :label="__('panel.vouchers.code')" :value="old('code', $voucher->code)" required maxlength="60" ltr
                                   :hint="__('panel.vouchers.codeHint')"/>
                    <x-panel.select name="type" :label="__('panel.vouchers.type')" data-switch :selected="$type"
                                    :options="['percentage' => __('panel.vouchers.types.percentage'), 'fixed' => __('panel.vouchers.types.fixed'), 'free_shipping' => __('panel.vouchers.types.free_shipping')]"/>
                    <x-panel.input name="name_ar" :label="__('panel.vouchers.nameAr')" :value="old('name_ar', $voucher->name_ar)" required maxlength="190" lang="ar" dir="rtl"/>
                    <x-panel.input name="name_en" :label="__('panel.vouchers.nameEn')" :value="old('name_en', $voucher->name_en)" required maxlength="190" lang="en" dir="ltr"/>
                    <div data-when="percentage" @if ($type !== 'percentage') hidden @endif>
                        <x-panel.input name="percent" :label="__('panel.products.discountPercent')" :value="$percent" inputmode="decimal" ltr suffix="%"/>
                    </div>
                    <div data-when="percentage" @if ($type !== 'percentage') hidden @endif>
                        <x-panel.input name="max_discount" :label="__('panel.vouchers.maxDiscount')" :value="$maxDiscount" inputmode="decimal" ltr :suffix="__('panel.common.currency')" :hint="__('panel.vouchers.maxDiscountHint')"/>
                    </div>
                    <div data-when="fixed" @if ($type !== 'fixed') hidden @endif>
                        <x-panel.input name="amount" :label="__('panel.products.discountAmount')" :value="$amount" inputmode="decimal" ltr :suffix="__('panel.common.currency')"/>
                    </div>
                    <x-panel.input name="min_subtotal" :label="__('panel.vouchers.minSubtotal')" :value="$minSubtotal" inputmode="decimal" ltr :suffix="__('panel.common.currency')" :hint="__('panel.vouchers.minSubtotalHint')"/>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>{{ __('panel.vouchers.validity') }}</h2></div>
                <div class="card__body form-grid">
                    <x-panel.input name="starts_at" type="datetime-local" :label="__('panel.products.discountStarts')" :value="$startsAt" ltr :hint="__('panel.vouchers.datesHint')"/>
                    <x-panel.input name="ends_at" type="datetime-local" :label="__('panel.products.discountEnds')" :value="$endsAt" ltr/>
                    <x-panel.input name="usage_limit" type="number" min="1" :label="__('panel.vouchers.usageLimit')" :value="old('usage_limit', $voucher->usage_limit)" ltr :hint="__('panel.vouchers.usageLimitHint')"/>
                    <x-panel.input name="usage_limit_per_customer" type="number" min="1" :label="__('panel.vouchers.perCustomer')" :value="old('usage_limit_per_customer', $voucher->usage_limit_per_customer)" ltr :hint="__('panel.vouchers.perCustomerHint')"/>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>{{ __('panel.categories.visibility') }}</h2></div>
                <div class="card__body stack" style="--gap:14px">
                    <x-panel.switch name="is_active" :label="__('panel.vouchers.enabled')" :checked="old('is_active', $voucher->is_active)"/>
                    <x-panel.switch name="is_public" :label="__('panel.vouchers.announce')" :checked="old('is_public', $voucher->is_public)" :hint="__('panel.vouchers.announceHint')"/>
                </div>
            </section>

            <div class="row">
                <button class="btn-p grow" type="submit">{{ __('panel.common.saveChanges') }}</button>
                <a class="btn-p btn-p--ghost" href="{{ route('panel.vouchers.index') }}">{{ __('panel.common.cancel') }}</a>
            </div>

            @if ($editing)
                <button class="btn-p btn-p--danger btn-p--block" type="submit" form="delete-voucher" data-danger
                        data-confirm="{{ __('panel.vouchers.confirmDelete') }}">{{ __('panel.common.delete') }}</button>
            @endif
        </div>
    </form>

    @if ($editing)
        <form id="delete-voucher" method="POST" action="{{ route('panel.vouchers.destroy', $voucher) }}" hidden>@csrf @method('DELETE')</form>
    @endif
@endsection
