@extends('layouts.app')

@section('title', $product->name())
@section('description', __('storefront.bundle.lede'))

@php use App\Support\Nav; @endphp

@section('content')
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="crumbs">
                <li><a href="{{ Nav::url() }}">{{ __('storefront.nav.home') }}</a></li>
                <li><a href="{{ Nav::url('packages') }}">{{ __('storefront.nav.packages') }}</a></li>
                <li><span aria-current="page">{{ $product->name() }}</span></li>
            </ol>
        </nav>

        <div class="pdp" style="align-items:start">
            <div class="gallery">
                <div class="gallery__stage">
                    @if ($product->image())
                        <img class="is-active" src="{{ $product->image() }}" alt="{{ $product->name() }}"
                             width="1000" height="1250" fetchpriority="high" decoding="async">
                    @endif
                </div>
            </div>

            <div class="pdp__info">
                <p class="pdp__kicker">{{ __('storefront.nav.packages') }}</p>
                <h1 class="pdp__title">{{ $product->name() }}</h1>

                <div class="pdp__price">
                    <x-price :product="$product" size="lg"/>
                    <p class="muted" style="margin-block-start:var(--space-2);font-size:var(--step-small)">
                        {{ __('storefront.bundle.price') }} — {{ $group['name'] }}
                    </p>
                </div>

                @if ($excerpt = $product->excerpt(200))
                    <p class="muted">{{ $excerpt }}</p>
                @endif
            </div>
        </div>

        {{-- --------------------------------------------- bundle builder --}}
        <section class="section" data-bundle data-size="{{ $group['max'] }}">
            <x-section-heading
                :eyebrow="__('storefront.nav.packages')"
                :title="__('storefront.bundle.title')"
                :lede="__('storefront.bundle.lede')"/>

            @error('values')
                <p class="alert alert--error" role="alert">
                    <x-icon name="info" size="16" class="alert__icon"/> {{ $message }}
                </p>
            @enderror

            <form method="POST" action="{{ Nav::url('packages/'.$product->slug()) }}">
                @csrf

                {{-- The numbered slots show progress; the real controls are the
                     checkboxes below, so the form works without JavaScript. --}}
                <div class="bundle-steps" style="margin-block-end:var(--space-6)">
                    @for ($i = 1; $i <= $group['max']; $i++)
                        <div class="bundle-slot" data-slot="{{ $i }}">
                            <span class="bundle-slot__index">
                                {{ str_pad((string) $i, 2, '0', STR_PAD_LEFT) }} —
                                {{ __('storefront.bundle.step', ['n' => $i]) }}
                            </span>
                            <span class="bundle-slot__label" data-slot-label>
                                {{ __('storefront.bundle.choose') }}
                            </span>
                            <span class="bundle-slot__chosen" data-slot-chosen hidden></span>
                        </div>
                    @endfor
                </div>

                <fieldset class="fieldset" style="margin-block-end:var(--space-6)">
                    <legend class="fieldset__legend">{{ $group['name'] }}</legend>

                    <div class="product-grid" role="group"
                         aria-describedby="bundle-progress">
                        @foreach ($group['values'] as $value)
                            <label class="product-card" style="cursor:pointer">
                                <span class="product-card__media">
                                    @if ($value['image'])
                                        <img class="product-card__img product-card__img--main"
                                             src="{{ $value['image'] }}" alt="" width="600" height="750"
                                             loading="lazy" decoding="async">
                                    @else
                                        <span class="product-card__img product-card__img--main"
                                              style="display:grid;place-items:center;font-family:var(--font-display);font-size:var(--step-heading);color:var(--ink-400)">
                                            {{ $value['name'] }}
                                        </span>
                                    @endif

                                    <span class="product-card__wish" aria-hidden="true" data-tick hidden
                                          style="background:var(--emerald-700);color:var(--cream-400)">
                                        <x-icon name="check" size="16"/>
                                    </span>
                                </span>

                                <span class="product-card__body" style="display:flex;align-items:center;gap:var(--space-3)">
                                    <input type="checkbox" name="values[]" value="{{ $value['id'] }}"
                                           data-bundle-option
                                           style="inline-size:20px;block-size:20px;accent-color:var(--emerald-700);flex:none">
                                    <span class="product-card__name" style="margin:0">{{ $value['name'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="bundle-bar">
                    <p class="bundle-bar__progress" id="bundle-progress" aria-live="polite" data-progress>
                        {{ __('storefront.bundle.remaining', ['count' => $group['max']]) }}
                    </p>

                    <div style="display:flex;align-items:center;gap:var(--space-4)">
                        <x-price :product="$product"/>
                        <button class="btn btn--on-dark" type="submit" data-bundle-submit disabled>
                            {{ __('storefront.bundle.addBundle') }}
                        </button>
                    </div>
                </div>
            </form>
        </section>
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
        choose:      @json(__('storefront.bundle.choose')),
        complete:    @json(__('storefront.bundle.complete')),
        remaining:   @json(__('storefront.bundle.remaining', ['count' => '%n'])),
        remainingOne:@json(__('storefront.bundle.remainingOne')),
    };

    function sync() {
        const chosen = boxes.filter((b) => b.checked);
        const left = size - chosen.length;

        // Once the package is full, the remaining options are closed off
        // rather than silently ignored on submit.
        boxes.forEach((b) => { b.disabled = !b.checked && left <= 0; });

        boxes.forEach((b) => {
            const tick = b.closest('label').querySelector('[data-tick]');
            if (tick) tick.hidden = !b.checked;
            b.closest('label').style.opacity = b.disabled ? '0.45' : '';
        });

        slots.forEach((slot, i) => {
            const pick = chosen[i];
            const label = slot.querySelector('[data-slot-label]');
            const shown = slot.querySelector('[data-slot-chosen]');

            slot.classList.toggle('is-filled', Boolean(pick));
            slot.classList.toggle('is-active', !pick && i === chosen.length);

            if (pick) {
                label.hidden = true;
                shown.hidden = false;
                shown.textContent = pick.closest('label').querySelector('.product-card__name').textContent.trim();
            } else {
                label.hidden = false;
                label.textContent = i18n.choose;
                shown.hidden = true;
            }
        });

        progress.textContent = left === 0
            ? i18n.complete
            : (left === 1 ? i18n.remainingOne : i18n.remaining.replace('%n', left));

        submit.disabled = left !== 0;
    }

    boxes.forEach((b) => b.addEventListener('change', sync));
    sync();
})();
</script>
@endpush
