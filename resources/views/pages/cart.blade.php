@extends('layouts.app')

@section('title', __('storefront.cart.title'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container">
        <div class="page-head">
            <h1 class="page-head__title">{{ __('storefront.cart.title') }}</h1>
            @if (! $quote->isEmpty())
                <p class="page-head__count">
                    {{ $quote->totalQuantity() }}
                    {{ $quote->totalQuantity() === 1 ? __('storefront.cart.item') : __('storefront.cart.items') }}
                </p>
            @endif
        </div>

        @if (session('status'))
            <p class="alert alert--success" role="status">
                <x-icon name="check" size="16" class="alert__icon"/> {{ session('status') }}
            </p>
        @endif

        @if (session('checkoutBlocked'))
            <p class="alert alert--error" role="alert">
                <x-icon name="info" size="16" class="alert__icon"/> {{ session('checkoutBlocked') }}
            </p>
        @endif

        @if ($quote->hasError())
            <p class="alert alert--error" role="alert">
                <x-icon name="info" size="16" class="alert__icon"/> {{ $quote->error }}
            </p>
        @endif

        @if ($quote->isEmpty())
            <x-empty-state icon="bag"
                           :title="__('storefront.cart.empty')"
                           :text="__('storefront.cart.emptyText')"
                           :href="Nav::url('products')"
                           :label="__('storefront.actions.keepShopping')"/>

            @if (count($suggestions))
                <section class="section">
                    <x-section-heading :title="__('storefront.home.bestTitle')" :href="Nav::url('best-sellers')"/>
                    <x-product-grid :products="$suggestions" rail/>
                </section>
            @endif
        @else
            @foreach ($quote->problems() as $problem)
                <p class="alert alert--error" role="alert">
                    <x-icon name="info" size="16" class="alert__icon"/> {{ $problem }}
                </p>
            @endforeach

            <div class="cart-layout" style="padding-block-end:var(--space-8)">
                <div>
                    @foreach ($quote->lines() as $line)
                        @php $product = $line['product']; @endphp

                        <div class="cart-line">
                            <div class="cart-line__media">
                                @if ($product?->image())
                                    <a href="{{ Nav::url('products/'.$product->slug()) }}">
                                        <img src="{{ $product->image() }}" alt="{{ $product->name() }}"
                                             width="120" height="150" loading="lazy" decoding="async">
                                    </a>
                                @endif
                            </div>

                            <div>
                                <div class="cart-line__top">
                                    <div>
                                        @if ($product?->primaryCategory())
                                            <p class="product-card__kicker">{{ $product->primaryCategory()['name'] }}</p>
                                        @endif
                                        <p class="cart-line__name">
                                            @if ($product)
                                                <a href="{{ Nav::url('products/'.$product->slug()) }}">{{ $product->name() }}</a>
                                            @endif
                                        </p>
                                        @if ($line['isFreeGift'])
                                            <span class="badge badge--gold">{{ __('storefront.cart.freeGift') }}</span>
                                        @endif
                                    </div>

                                    @if ($line['key'])
                                        <form method="POST" action="{{ Nav::url('cart/'.$line['key']) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="icon-btn"
                                                    aria-label="{{ __('storefront.actions.remove') }}">
                                                <x-icon name="trash" size="16"/>
                                            </button>
                                        </form>
                                    @endif
                                </div>

                                @if (! $line['available'] && $line['message'])
                                    <p class="field__error">{{ $line['message'] }}</p>
                                @endif

                                <div class="cart-line__foot">
                                    @if ($line['key'])
                                        <form method="POST" action="{{ Nav::url('cart/'.$line['key']) }}" class="qty">
                                            @csrf @method('PATCH')
                                            <button type="submit" name="quantity" value="{{ $line['quantity'] - 1 }}"
                                                    aria-label="{{ __('storefront.product.decrease') }}">
                                                <x-icon name="minus" size="16"/>
                                            </button>
                                            <label class="visually-hidden" for="q-{{ $line['key'] }}">
                                                {{ __('storefront.product.quantity') }}
                                            </label>
                                            <input id="q-{{ $line['key'] }}" type="number" name="quantity"
                                                   value="{{ $line['quantity'] }}" min="1" max="99"
                                                   inputmode="numeric" onchange="this.form.submit()">
                                            <button type="submit" name="quantity" value="{{ $line['quantity'] + 1 }}"
                                                    aria-label="{{ __('storefront.product.increase') }}">
                                                <x-icon name="plus" size="16"/>
                                            </button>
                                        </form>
                                    @else
                                        <span class="badge badge--gold">{{ __('storefront.cart.freeGift') }}</span>
                                    @endif

                                    <x-price :now="$line['total']" :symbol="$line['symbol']"/>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <p class="cart-keep-shopping" style="margin-block-start:var(--space-5)">
                        <a class="link-underline" href="{{ Nav::url('products') }}">
                            <x-icon name="arrow" size="16" class="icon-arrow"/>
                            {{ __('storefront.actions.keepShopping') }}
                        </a>
                    </p>
                </div>

                {{-- ------------------------------------------- summary --}}
                <aside>
                    {{-- Offer progress sits above the totals, where it can still
                         change the shopper's mind. --}}
                    <div style="margin-block-end:var(--space-4)">
                        <x-promo-meter :quote="$quote"/>
                    </div>

                    <div class="summary">
                        <h2 class="panel__title" style="margin-block-end:var(--space-4)">
                            {{ __('storefront.checkout.summary') }}
                        </h2>

                        <form method="POST" action="{{ Nav::url('cart/voucher') }}" class="voucher-form">
                            @csrf
                            <label class="visually-hidden" for="voucher">{{ __('storefront.cart.voucher') }}</label>
                            <input class="input" id="voucher" type="text" name="voucher"
                                   value="{{ $quote->voucherCode() }}"
                                   placeholder="{{ __('storefront.cart.voucherPlaceholder') }}"
                                   @if ($errors->has('voucher')) aria-invalid="true" @endif>
                            <button class="btn btn--ghost btn--sm" type="submit">
                                {{ __('storefront.actions.apply') }}
                            </button>
                        </form>

                        @error('voucher')
                            <p class="field__error">{{ $message }}</p>
                        @enderror

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

                            @if ($quote->pointsDiscount() > 0)
                                <div class="summary__row summary__row--discount">
                                    <dt>{{ __('storefront.cart.points') }}</dt>
                                    <dd>−{{ $quote->money($quote->pointsDiscount()) }}</dd>
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

                            @if ($quote->vat() > 0)
                                <div class="summary__row">
                                    <dt>{{ __('storefront.cart.vat') }}</dt>
                                    <dd>{{ $quote->money($quote->vat()) }}</dd>
                                </div>
                            @endif

                            <div class="summary__row summary__row--total">
                                <dt>{{ __('storefront.cart.total') }}</dt>
                                <dd>{{ $quote->money($quote->total()) }}</dd>
                            </div>
                        </dl>

                        @unless ($quote->meetsMinimum())
                            <p class="alert alert--notice" style="margin-block-start:var(--space-4)">
                                <x-icon name="info" size="16" class="alert__icon"/>
                                {{ __('storefront.cart.minimumOrder', [
                                    'amount' => $quote->money($quote->minimumOrderAmount()),
                                ]) }}
                            </p>
                        @endunless

                        <div class="sticky-cta">
                            {{-- Below desktop width the fixed bar below owns "checkout", so
                                 this box offers "continue shopping" instead; on desktop
                                 there is no bar and the box keeps the checkout button.
                                 aria-disabled on a link still follows on click, so a
                                 cart the API will not accept gets a real disabled
                                 button instead of a link that leads to a dead end. --}}
                            @if ($quote->canPlaceOrder())
                                <a class="btn btn--lg btn--block cart-cta__checkout" href="{{ Nav::url('checkout') }}">
                                    {{ __('storefront.actions.checkout') }}
                                </a>
                            @else
                                <button class="btn btn--lg btn--block cart-cta__checkout" type="button" disabled
                                        aria-describedby="checkout-blocked">
                                    {{ __('storefront.actions.checkout') }}
                                </button>
                            @endif

                            <a class="btn btn--lg btn--block btn--ghost cart-cta__shop" href="{{ Nav::url('products') }}">
                                {{ __('storefront.actions.keepShopping') }}
                            </a>

                            @unless ($quote->canPlaceOrder())
                                <p class="field__hint center" id="checkout-blocked">
                                    {{ $quote->problems()[0] ?? __('storefront.cart.unavailable') }}
                                </p>
                            @endunless
                        </div>
                    </div>
                </aside>
            </div>

            {{-- Phones get the checkout action as a fixed bar, always in reach. --}}
            <div class="checkout-bar">
                <span class="checkout-bar__total">
                    <span class="checkout-bar__label">{{ __('storefront.cart.total') }}</span>
                    <strong>{{ $quote->money($quote->total()) }}</strong>
                </span>

                @if ($quote->canPlaceOrder())
                    <a class="btn btn--lg" href="{{ Nav::url('checkout') }}">
                        {{ __('storefront.actions.checkout') }}
                    </a>
                @else
                    <button class="btn btn--lg" type="button" disabled>
                        {{ __('storefront.actions.checkout') }}
                    </button>
                @endif
            </div>
        @endif
    </div>
@endsection
