@extends('layouts.app')

@section('title', $product->name())
@section('description', __('storefront.bundle.lede'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container set-page">
        <nav aria-label="breadcrumb">
            <ol class="crumbs">
                <li><a href="{{ Nav::url() }}">{{ __('storefront.nav.home') }}</a></li>
                <li><a href="{{ Nav::url('packages') }}">{{ __('storefront.nav.packages') }}</a></li>
                <li><span aria-current="page">{{ $product->name() }}</span></li>
            </ol>
        </nav>

        {{-- Kept short on purpose: the pieces to choose from are what the shopper came for. --}}
        <header class="set-hero">
            @if ($product->image())
                <img class="set-hero__img" src="{{ $product->image() }}" alt="" width="200" height="250"
                     fetchpriority="high" decoding="async">
            @endif

            <div class="set-hero__text">
                <p class="set-hero__kicker">{{ __('storefront.nav.packages') }}</p>
                <h1 class="set-hero__title">{{ $product->name() }}</h1>
                <p class="set-hero__lede">{{ __('storefront.bundle.lede') }}</p>
                <p class="set-hero__price">
                    <x-price :product="$product" size="lg"/>
                    <span class="set-hero__price-note">{{ __('storefront.bundle.price') }}</span>
                </p>
            </div>
        </header>

        @unless ($available)
            <p class="alert alert--error" role="alert">
                <x-icon name="info" size="16" class="alert__icon"/> {{ __('storefront.bundle.unavailable') }}
            </p>
        @endunless

        @error('values')
            <p class="alert alert--error" role="alert">
                <x-icon name="info" size="16" class="alert__icon"/> {{ $message }}
            </p>
        @enderror

        {{-- --------------------------------------------- bundle builder --}}
        <form method="POST" action="{{ Nav::url('packages/'.$product->slug()) }}"
              data-bundle data-size="{{ $group['max'] }}">
            @csrf

            {{-- The slots show what has been picked so far; the real controls are the
                 checkboxes below, so the form works without JavaScript. --}}
            <div class="set-tray">
                @for ($i = 1; $i <= $group['max']; $i++)
                    <button type="button" class="set-slot" data-slot="{{ $i }}" disabled aria-hidden="true">
                        <span class="set-slot__num">{{ $i }}</span>
                        <span class="set-slot__empty" data-slot-empty><x-icon name="plus" size="18"/></span>
                        <span class="set-slot__chosen" data-slot-chosen hidden>
                            <img src="" alt="" width="56" height="70" data-slot-img>
                            <span class="set-slot__name" data-slot-name></span>
                        </span>
                    </button>
                @endfor
            </div>

            <fieldset class="set-pieces">
                <legend class="visually-hidden">{{ __('storefront.bundle.title') }}</legend>

                <div class="piece-grid" role="group" aria-describedby="bundle-progress">
                    @foreach ($group['values'] as $value)
                        <label class="piece @if ($value['soldOut']) is-sold-out @endif">
                            <input class="piece__input visually-hidden" type="checkbox" name="values[]"
                                   value="{{ $value['id'] }}" data-bundle-option
                                   @if ($value['soldOut']) disabled data-sold-out @endif>

                            <span class="piece__media">
                                @if ($value['image'])
                                    <img src="{{ $value['image'] }}" alt="" width="300" height="375"
                                         loading="lazy" decoding="async" data-piece-img>
                                @endif
                                <span class="piece__tick" aria-hidden="true"><x-icon name="check" size="14"/></span>
                                @if ($value['soldOut'])
                                    <span class="piece__soldout">{{ __('storefront.product.soldOut') }}</span>
                                @endif
                            </span>

                            @if ($value['kicker'] !== '')
                                <span class="piece__kicker">{{ $value['kicker'] }}</span>
                            @endif
                            <span class="piece__name" data-piece-name>{{ $value['name'] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="bundle-bar">
                <p class="bundle-bar__progress" id="bundle-progress" aria-live="polite" data-progress>
                    {{ __('storefront.bundle.remaining', ['count' => $group['max']]) }}
                </p>

                <div class="bundle-bar__buy">
                    <x-price :product="$product"/>
                    <button class="btn btn--on-dark" type="submit" data-bundle-submit disabled
                            @unless ($available) data-unavailable @endunless>
                        {{ __('storefront.bundle.addBundle') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    const root = document.querySelector('[data-bundle]');
    if (!root) return;

    const size = Number(root.dataset.size);
    const boxes = Array.from(root.querySelectorAll('[data-bundle-option]'));
    const slots = Array.from(root.querySelectorAll('[data-slot]'));
    const progress = root.querySelector('[data-progress]');
    const submit = root.querySelector('[data-bundle-submit]');

    const i18n = {
        remove:      @json(__('storefront.actions.remove')),
        complete:    @json(__('storefront.bundle.complete')),
        remaining:   @json(__('storefront.bundle.remaining', ['count' => '%n'])),
        remainingOne:@json(__('storefront.bundle.remainingOne')),
    };

    // The order the shopper picked them in, so the slots fill from the first one.
    let picked = [];

    function sync() {
        picked = picked.filter((b) => b.checked);
        boxes.filter((b) => b.checked && !picked.includes(b)).forEach((b) => picked.push(b));

        const left = size - picked.length;

        // Once the set is full, the remaining pieces are closed off rather than
        // silently ignored on submit.
        boxes.forEach((b) => {
            b.disabled = 'soldOut' in b.dataset || (!b.checked && left <= 0);
            b.closest('.piece').classList.toggle('is-closed', b.disabled && !('soldOut' in b.dataset));
        });

        slots.forEach((slot, i) => {
            const box = picked[i];
            const card = box ? box.closest('.piece') : null;
            const img = card ? card.querySelector('[data-piece-img]') : null;

            slot.classList.toggle('is-filled', Boolean(box));
            slot.classList.toggle('is-active', !box && i === picked.length);
            slot.querySelector('[data-slot-empty]').hidden = Boolean(box);
            slot.querySelector('[data-slot-chosen]').hidden = !box;
            // Only a filled slot is a control (it takes its piece out again).
            slot.disabled = !box;
            slot.setAttribute('aria-hidden', box ? 'false' : 'true');

            if (box) {
                const image = slot.querySelector('[data-slot-img]');
                image.hidden = !img;
                if (img) image.src = img.currentSrc || img.src;
                const name = card.querySelector('[data-piece-name]').textContent.trim();
                slot.querySelector('[data-slot-name]').textContent = name;
                slot.setAttribute('aria-label', i18n.remove + ': ' + name);
            }
        });

        progress.textContent = left === 0
            ? i18n.complete
            : (left === 1 ? i18n.remainingOne : i18n.remaining.replace('%n', left));

        submit.disabled = left !== 0 || 'unavailable' in submit.dataset;
    }

    // A filled slot can be tapped to take that piece out again.
    slots.forEach((slot, i) => slot.addEventListener('click', () => {
        const box = picked[i];
        if (!box) return;
        box.checked = false;
        sync();
    }));

    boxes.forEach((b) => b.addEventListener('change', sync));
    sync();
})();
</script>
@endpush
