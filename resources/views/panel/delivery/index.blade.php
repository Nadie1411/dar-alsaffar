@extends('panel.layout')

@section('title', __('panel.modules.delivery'))

@section('content')
    <x-panel.page-head :title="__('panel.modules.delivery')" :sub="__('panel.delivery.sub')"/>

    <div class="grid grid--main">
        <section class="card">
            <div class="card__head">
                <h2>{{ __('panel.delivery.cities') }}</h2>
            </div>
            @if ($cities->isEmpty())
                <x-panel.empty icon="delivery" :title="__('panel.delivery.noCities')" :text="__('panel.delivery.noCitiesHint')"/>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>{{ __('panel.orders.city') }}</th>
                            <th class="col-num">{{ __('panel.delivery.areas') }}</th>
                            <th>{{ __('panel.common.status') }}</th>
                            <th class="col-actions">{{ __('panel.common.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($cities as $city)
                            <tr>
                                <td>
                                    <a class="cell-main" href="{{ route('panel.delivery.cities.edit', $city) }}">{{ $city->localized('name') }}</a>
                                    <span class="cell-sub">{{ app()->getLocale() === 'ar' ? $city->name_en : $city->name_ar }}</span>
                                </td>
                                <td class="col-num">{{ $city->active_areas_count }} / {{ $city->areas_count }}</td>
                                <td><x-panel.pill :tone="$city->is_active ? 'green' : 'grey'">{{ $city->is_active ? __('panel.common.active') : __('panel.common.inactive') }}</x-panel.pill></td>
                                <td class="col-actions"><a class="btn-p btn-p--ghost btn-p--sm" href="{{ route('panel.delivery.cities.edit', $city) }}">{{ __('panel.delivery.manageAreas') }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <form class="card__foot row" method="POST" action="{{ route('panel.delivery.cities.store') }}">
                @csrf
                <input class="in" style="inline-size:auto;flex:1;min-inline-size:160px" name="name_ar" placeholder="{{ __('panel.categories.nameAr') }}" required maxlength="190" lang="ar" dir="rtl" aria-label="{{ __('panel.categories.nameAr') }}">
                <input class="in" style="inline-size:auto;flex:1;min-inline-size:160px" name="name_en" placeholder="{{ __('panel.categories.nameEn') }}" required maxlength="190" lang="en" dir="ltr" aria-label="{{ __('panel.categories.nameEn') }}">
                <button class="btn-p" type="submit"><x-panel.icon name="plus" :size="18"/>{{ __('panel.delivery.addCity') }}</button>
            </form>
        </section>

        <form class="card" method="POST" action="{{ route('panel.delivery.settings') }}">
            @csrf @method('PUT')
            <div class="card__head"><h2>{{ __('panel.delivery.settings') }}</h2></div>
            <div class="card__body stack" style="--gap:16px">
                <x-panel.input name="delivery_fee" :label="__('panel.delivery.defaultFee')" :value="old('delivery_fee', $deliveryFee)" required inputmode="decimal" ltr :suffix="__('panel.common.currency')" :hint="__('panel.delivery.defaultFeeHint')"/>
                <x-panel.input name="free_shipping" :label="__('panel.delivery.freeFrom')" :value="old('free_shipping', $freeShipping)" inputmode="decimal" ltr :suffix="__('panel.common.currency')" :hint="__('panel.delivery.freeFromHint')"/>
                <x-panel.input name="minimum_order" :label="__('panel.delivery.minimumOrder')" :value="old('minimum_order', $minimumOrder)" inputmode="decimal" ltr :suffix="__('panel.common.currency')" :hint="__('panel.delivery.minimumOrderHint')"/>
            </div>
            <div class="card__foot"><button class="btn-p" type="submit">{{ __('panel.common.saveChanges') }}</button></div>
        </form>
    </div>
@endsection
