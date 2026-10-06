@props(['code'])

{{-- A discount code that copies itself on tap (see data-copy-code in promo.js). --}}
<button type="button" {{ $attributes->class('offer-strip__code') }}
        data-copy-code="{{ $code }}"
        title="{{ __('storefront.promo.copyCode', ['code' => $code]) }}"
        aria-label="{{ __('storefront.promo.copyCode', ['code' => $code]) }}">
    <bdi dir="ltr">{{ $code }}</bdi>
    <x-icon name="copy" size="14" class="offer-strip__copy"/>
    <x-icon name="check" size="14" class="offer-strip__done"/>
</button>
