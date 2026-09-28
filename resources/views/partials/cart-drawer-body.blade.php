@php use App\Support\Nav; @endphp

@if ($quote->hasError())
    <p class="alert alert--error">
        <x-icon name="info" size="16" class="alert__icon"/>
        {{ $quote->error }}
    </p>
@elseif ($quote->isEmpty())
    <x-empty-state icon="bag"
                   :title="__('storefront.cart.empty')"
                   :text="__('storefront.cart.emptyText')"
                   :href="Nav::url('products')"
                   :label="__('storefront.actions.keepShopping')"/>
@else
    <x-promo-meter :quote="$quote"/>

    <div>
        @foreach ($quote->lines() as $line)
            @php $product = $line['product']; @endphp

            <div class="cart-line" style="grid-template-columns:72px minmax(0,1fr);padding-block:var(--space-4)">
                <div class="cart-line__media">
                    @if ($product?->image())
                        <img src="{{ $product->image() }}" alt="" width="72" height="90" loading="lazy">
                    @endif
                </div>

                <div>
                    <div class="cart-line__top">
                        <p class="cart-line__name" style="font-size:var(--step-small)">
                            @if ($product)
                                <a href="{{ Nav::url('products/'.$product->slug()) }}">{{ $product->name() }}</a>
                            @endif
                            @if ($line['isFreeGift'])
                                <span class="badge badge--gold">{{ __('storefront.cart.freeGift') }}</span>
                            @endif
                        </p>

                        @if ($line['key'])
                            <button type="button" class="icon-btn" style="inline-size:32px;block-size:32px"
                                    data-cart-step="{{ $line['key'] }}" data-qty="0"
                                    aria-label="{{ __('storefront.actions.remove') }}">
                                <x-icon name="close" size="14"/>
                            </button>
                        @endif
                    </div>

                    @if (! $line['available'] && $line['message'])
                        <p class="field__error">{{ $line['message'] }}</p>
                    @endif

                    <div class="cart-line__foot" style="margin-block-start:var(--space-2)">
                        @if ($line['key'])
                        <span class="qty" style="block-size:36px">
                            <button type="button" data-cart-step="{{ $line['key'] }}"
                                    data-qty="{{ $line['quantity'] - 1 }}"
                                    aria-label="{{ __('storefront.product.decrease') }}">
                                <x-icon name="minus" size="14"/>
                            </button>
                            <span style="inline-size:36px;text-align:center;font-variant-numeric:tabular-nums">
                                {{ $line['quantity'] }}
                            </span>
                            <button type="button" data-cart-step="{{ $line['key'] }}"
                                    data-qty="{{ $line['quantity'] + 1 }}"
                                    aria-label="{{ __('storefront.product.increase') }}">
                                <x-icon name="plus" size="14"/>
                            </button>
                        </span>
                        @else
                            <span class="badge badge--gold">{{ __('storefront.cart.freeGift') }}</span>
                        @endif

                        <x-price :now="$line['total']" :symbol="$line['symbol']"/>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif

@if (! $quote->isEmpty() && ! $quote->hasError())
    {{-- Rendered inside the drawer body so the totals refresh with the lines. --}}
    <div style="margin-block-start:var(--space-5);padding-block-start:var(--space-4);border-block-start:1px solid var(--line)">
        <dl>
            <div class="summary__row">
                <dt>{{ __('storefront.cart.subtotal') }}</dt>
                <dd>{{ $quote->money($quote->subTotal()) }}</dd>
            </div>

            @if ($quote->discount() > 0)
                <div class="summary__row summary__row--discount">
                    <dt>{{ __('storefront.cart.discount') }}</dt>
                    <dd>−{{ $quote->money($quote->discount()) }}</dd>
                </div>
            @endif

            <div class="summary__row">
                <dt>{{ __('storefront.cart.delivery') }}</dt>
                <dd>
                    @if ($quote->deliveryResolved())
                        {{ $quote->money($quote->deliveryFees()) }}
                    @else
                        <span class="muted">{{ __('storefront.cart.deliveryAtCheckout') }}</span>
                    @endif
                </dd>
            </div>

            <div class="summary__row summary__row--total">
                <dt>{{ __('storefront.cart.total') }}</dt>
                <dd>{{ $quote->money($quote->total()) }}</dd>
            </div>
        </dl>

        <div class="stack" style="--flow:var(--space-2);margin-block-start:var(--space-4)">
            <a class="btn btn--block" href="{{ Nav::url('checkout') }}">{{ __('storefront.actions.checkout') }}</a>
            <button type="button" class="btn btn--ghost btn--block" data-close>
                {{ __('storefront.actions.keepShopping') }}
            </button>
        </div>
    </div>
@endif
