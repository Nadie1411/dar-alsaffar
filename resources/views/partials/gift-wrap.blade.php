@props(['addons', 'required' => false, 'symbol' => null])

@php use App\Support\Money; @endphp

{{-- Only rendered once the shop has created a gift-wrap add-on and switched
     it on for delivery orders. Until then this section is absent rather than
     showing an option that cannot be fulfilled. --}}
@if (count($addons))
    <fieldset class="fieldset">
        <legend class="fieldset__legend">
            <x-icon name="gift" size="20"/>
            {{ __('storefront.addon.title') }}
        </legend>

        <p class="muted" style="margin-block-start:calc(var(--space-5) * -1 + var(--space-2));margin-block-end:var(--space-4);font-size:var(--step-small)">
            {{ __('storefront.addon.lede') }}
        </p>

        <div class="stack" style="--flow:var(--space-2)">
            @unless ($required)
                <label class="choice">
                    <input type="radio" name="addons[]" value="" @checked(! old('addons'))>
                    <span class="choice__label">{{ __('storefront.addon.none') }}</span>
                </label>
            @endunless

            @foreach ($addons as $addon)
                <label class="choice">
                    <input type="radio" name="addons[]" value="{{ $addon['id'] }}"
                           @checked(in_array($addon['id'], (array) old('addons', []), true))
                           @if ($required && $loop->first && ! old('addons')) checked @endif>
                    <span>
                        <span class="choice__label">{{ $addon['name'] }}</span>
                        @if ($addon['description'])
                            <span class="choice__note">{{ $addon['description'] }}</span>
                        @endif
                    </span>
                    <span class="choice__note" style="margin-inline-start:auto;font-variant-numeric:tabular-nums">
                        {{ $addon['price'] > 0
                            ? '+ '.Money::format($addon['price'], $symbol)
                            : __('storefront.addon.freeLabel') }}
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>
@endif
