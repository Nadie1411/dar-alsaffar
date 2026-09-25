@props(['quote'])

@php
    use App\Support\Money;

    $gift = $quote->freeGift();
    $ship = $quote->freeShipping();
@endphp

{{-- Both meters read live values from the cart checker. If the store has no
     gift promotion or no free-delivery threshold configured, nothing renders —
     there is no placeholder offer. --}}

@if ($gift)
    <div @class(['promo-meter', 'celebrate', 'promo-meter--unlocked' => $gift['inCart']])
         data-celebrate="{{ $gift['inCart'] ? 'on' : 'off' }}"
         data-gift-key="{{ $gift['voucherId'] }}:{{ $gift['inCart'] ? 'in' : 'waiting' }}">

        <div class="promo-meter__head">
            <x-icon :name="$gift['inCart'] ? 'check' : 'gift'" size="22" class="promo-meter__icon"/>

            <div class="promo-meter__text">
                <p class="promo-meter__title">
                    {{ $gift['inCart'] ? __('storefront.promo.giftUnlocked') : __('storefront.promo.giftTitle') }}
                </p>
                <p class="promo-meter__note">
                    @if ($gift['inCart'])
                        {{ __('storefront.promo.giftUnlockedBody', ['name' => $gift['giftName'] ?? '']) }}
                    @elseif ($gift['remainingQuantity'] === 1)
                        {{ __('storefront.promo.giftRemainingOne') }}
                    @elseif ($gift['remainingQuantity'] > 1)
                        {{ __('storefront.promo.giftRemainingQty', ['count' => $gift['remainingQuantity']]) }}
                    @elseif ($gift['remainingAmount'] > 0)
                        {{ __('storefront.promo.giftRemainingAmt', [
                            'amount' => $quote->money($gift['remainingAmount']),
                        ]) }}
                    @endif
                </p>
            </div>
        </div>

        <div class="promo-meter__track"
             role="progressbar"
             aria-valuenow="{{ $gift['percent'] }}"
             aria-valuemin="0" aria-valuemax="100"
             aria-label="{{ __('storefront.promo.progressLabel') }}">
            <span class="promo-meter__fill" style="inline-size:{{ $gift['percent'] }}%"></span>
        </div>

        @if ($gift['giftName'])
            <div class="promo-meter__gift">
                @if ($gift['giftImage'])
                    <img src="{{ $gift['giftImage'] }}" alt="" width="40" height="50" loading="lazy">
                @endif
                <span style="flex:1;min-inline-size:0">
                    <span class="promo-meter__title" style="display:block">{{ $gift['giftName'] }}</span>
                    <span class="badge badge--gold">{{ __('storefront.promo.giftLine') }}</span>
                </span>
                @if ($gift['quantity'] > 1)
                    <span class="muted" style="font-size:var(--step-micro)">× {{ $gift['quantity'] }}</span>
                @endif
            </div>
        @endif
    </div>
@endif

@if ($ship)
    <div @class(['promo-meter', 'promo-meter--unlocked' => $ship['unlocked']])>
        <div class="promo-meter__head">
            <x-icon :name="$ship['unlocked'] ? 'check' : 'truck'" size="22" class="promo-meter__icon"/>

            <div class="promo-meter__text">
                <p class="promo-meter__title">{{ __('storefront.promo.shipTitle') }}</p>
                <p class="promo-meter__note">
                    {{ $ship['unlocked']
                        ? __('storefront.promo.shipUnlocked')
                        : __('storefront.promo.shipRemaining', ['amount' => $quote->money($ship['remaining'])]) }}
                </p>
            </div>
        </div>

        <div class="promo-meter__track"
             role="progressbar"
             aria-valuenow="{{ $ship['percent'] }}"
             aria-valuemin="0" aria-valuemax="100"
             aria-label="{{ __('storefront.promo.shipTitle') }}">
            <span class="promo-meter__fill" style="inline-size:{{ $ship['percent'] }}%"></span>
        </div>
    </div>
@endif
