@php use App\Support\Nav; @endphp

@if (app(\App\Services\Settings::class)->bool('strip.enabled') && $offer)
    {{-- The headline offer, exactly as configured upstream. --}}
    <aside class="offer-strip" role="complementary" aria-label="{{ __('storefront.promo.eyebrow') }}">
        <x-icon :name="$offer['type'] === 'buy_x_get_y' ? 'gift' : 'sparkle'" size="16"/>

        <span><strong style="font-weight:500">{{ $offer['headline'] }}</strong></span>
        <span style="opacity:.8">{{ $offer['scope']['label'] }}</span>

        @if ($offer['code'])
            <span class="offer-strip__code">
                <span class="visually-hidden">{{ __('storefront.promo.code') }}</span>
                {{ $offer['code'] }}
            </span>
        @endif

        <a class="link-underline" href="{{ Nav::url('offers') }}" style="color:var(--gold-300)">
            {{ __('storefront.promo.seeOffers') }}
        </a>
    </aside>
@endif
