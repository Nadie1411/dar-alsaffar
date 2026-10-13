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

        <header class="set-hero">
            <p class="set-hero__kicker">{{ __('storefront.nav.packages') }}</p>
            <h1 class="set-hero__title">{{ $product->name() }}</h1>
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

            {{-- Where the set stands: one step for each piece, filled as they are chosen. --}}
            <div class="set-progress">
                <p class="set-progress__status" id="bundle-progress" aria-live="polite" data-progress>
                    {{ __('storefront.bundle.stepOf', ['n' => 1, 'total' => $group['max']]) }}
                </p>

                <div class="set-steps">
                    @for ($i = 1; $i <= $group['max']; $i++)
                        <button type="button" class="set-step" data-step="{{ $i }}" disabled aria-hidden="true">
                            <span class="set-step__num">{{ $i }}</span>
                            <img class="set-step__img" src="" alt="" width="52" height="52" data-step-img hidden>
                        </button>
                    @endfor
                </div>
            </div>

            {{-- The products to choose from, one card at a time to swipe through. The real
                 controls are the checkboxes inside the cards, so the form works without
                 JavaScript too. --}}
            <fieldset class="set-stage">
                <legend class="visually-hidden">{{ __('storefront.bundle.title') }}</legend>

                <div class="set-stage__rail">
                    <button type="button" class="set-nav set-nav--prev" data-nav="-1" hidden
                            aria-label="{{ __('storefront.bundle.previous') }}">
                        <x-icon name="chevron" size="20"/>
                    </button>

                    <div class="set-track" data-track tabindex="-1">
                        @foreach ($group['values'] as $value)
                            <label class="pick @if ($value['soldOut']) is-sold-out @endif" data-slide>
                                <input class="visually-hidden" type="checkbox" name="values[]"
                                       value="{{ $value['id'] }}" data-bundle-option
                                       @if ($value['soldOut']) disabled data-sold-out @endif>

                                <span class="pick__media">
                                    @if ($value['image'])
                                        <img src="{{ $value['image'] }}" alt="" width="300" height="375"
                                             loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}" decoding="async" data-piece-img>
                                    @endif
                                    <span class="pick__badge" aria-hidden="true">
                                        <x-icon name="check" size="12"/><span data-badge></span>
                                    </span>
                                </span>

                                <span class="pick__body">
                                    @if ($value['kicker'] !== '')
                                        <span class="pick__kicker">{{ $value['kicker'] }}</span>
                                    @endif
                                    <span class="pick__name" data-piece-name>{{ $value['name'] }}</span>
                                </span>

                                {{-- One of these shows, whichever way the card stands. --}}
                                <span class="pick__action">
                                    <span class="pick__action-add"><x-icon name="check" size="16"/> {{ __('storefront.bundle.pick') }}</span>
                                    <span class="pick__action-on">{{ __('storefront.actions.remove') }}</span>
                                    <span class="pick__action-full">{{ __('storefront.bundle.complete') }}</span>
                                    <span class="pick__action-out">{{ __('storefront.product.soldOut') }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <button type="button" class="set-nav set-nav--next" data-nav="1" hidden
                            aria-label="{{ __('storefront.bundle.next') }}">
                        <x-icon name="chevron" size="20"/>
                    </button>
                </div>

                <p class="set-stage__count" dir="ltr" data-count aria-hidden="true"></p>
            </fieldset>

            <div class="bundle-bar">
                <x-price :product="$product"/>
                <button class="btn" type="submit" data-bundle-submit disabled
                        @unless ($available) data-unavailable @endunless>
                    {{ __('storefront.bundle.addBundle') }}
                </button>
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
    const track = root.querySelector('[data-track]');
    const slides = Array.from(track.querySelectorAll('[data-slide]'));
    const boxes = slides.map((slide) => slide.querySelector('[data-bundle-option]'));
    const steps = Array.from(root.querySelectorAll('[data-step]'));
    const navs = Array.from(root.querySelectorAll('[data-nav]'));
    const progress = root.querySelector('[data-progress]');
    const counter = root.querySelector('[data-count]');
    const submit = root.querySelector('[data-bundle-submit]');
    const rtl = getComputedStyle(root).direction === 'rtl';
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const behavior = calm ? 'auto' : 'smooth';

    const i18n = {
        remove:   @json(__('storefront.actions.remove')),
        complete: @json(__('storefront.bundle.complete')),
        stepOf:   @json(__('storefront.bundle.stepOf', ['n' => '%n', 'total' => '%t'])),
    };

    // The order the shopper picked them in, so step 1 is the first one chosen.
    let picked = [];

    function sync() {
        picked = picked.filter((box) => box.checked);
        boxes.filter((box) => box.checked && !picked.includes(box)).forEach((box) => picked.push(box));

        const left = size - picked.length;

        // Once the set is full, the rest are closed off rather than silently
        // ignored on submit.
        boxes.forEach((box, i) => {
            const sold = 'soldOut' in box.dataset;
            box.disabled = sold || (!box.checked && left <= 0);
            slides[i].classList.toggle('is-closed', box.disabled && !sold);
            slides[i].querySelector('[data-badge]').textContent = box.checked ? picked.indexOf(box) + 1 : '';
        });

        steps.forEach((step, i) => {
            const box = picked[i];
            const img = box ? slides[boxes.indexOf(box)].querySelector('[data-piece-img]') : null;
            const image = step.querySelector('[data-step-img]');

            step.classList.toggle('is-filled', Boolean(box));
            step.classList.toggle('is-next', !box && i === picked.length);
            step.disabled = !box;
            step.setAttribute('aria-hidden', box ? 'false' : 'true');
            image.hidden = !img;

            if (img) image.src = img.currentSrc || img.src;
            if (box) step.setAttribute('aria-label', i18n.remove + ': ' + slides[boxes.indexOf(box)].querySelector('[data-piece-name]').textContent.trim());
        });

        progress.textContent = left === 0
            ? i18n.complete
            : i18n.stepOf.replace('%n', picked.length + 1).replace('%t', size);
        progress.classList.toggle('is-complete', left === 0);

        submit.disabled = left !== 0 || 'unavailable' in submit.dataset;
    }

    // ---- the rail of cards -------------------------------------------------

    function step() {
        const first = slides[0];
        const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
        return first ? first.getBoundingClientRect().width + gap : 0;
    }

    // Which card sits in the middle of the rail now.
    function current() {
        const mid = track.getBoundingClientRect().left + track.clientWidth / 2;
        let best = 0;
        let distance = Infinity;

        slides.forEach((slide, i) => {
            const box = slide.getBoundingClientRect();
            const d = Math.abs(box.left + box.width / 2 - mid);
            if (d < distance) { distance = d; best = i; }
        });

        return best;
    }

    function show(slide) {
        slide.scrollIntoView({ behavior, inline: 'center', block: 'nearest' });
    }

    function refreshRail() {
        const index = current();
        counter.textContent = (index + 1) + ' / ' + slides.length;

        // How far along the rail it is, whichever way the page reads.
        const along = Math.abs(track.scrollLeft);
        const atStart = along < 4;
        const atEnd = along + track.clientWidth >= track.scrollWidth - 4;
        const overflowing = track.scrollWidth > track.clientWidth + 4;

        navs.forEach((nav) => {
            nav.hidden = !overflowing;
            nav.disabled = Number(nav.dataset.nav) > 0 ? atEnd : atStart;
        });
    }

    navs.forEach((nav) => nav.addEventListener('click', () => {
        const direction = Number(nav.dataset.nav) * (rtl ? -1 : 1);
        track.scrollBy({ left: direction * step(), behavior });
    }));

    let frame = 0;
    track.addEventListener('scroll', () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(refreshRail);
    }, { passive: true });
    window.addEventListener('resize', refreshRail);

    // A step that is filled takes the shopper back to that card.
    steps.forEach((stepButton, i) => stepButton.addEventListener('click', () => {
        const box = picked[i];
        if (box) show(slides[boxes.indexOf(box)]);
    }));

    boxes.forEach((box, i) => box.addEventListener('change', () => {
        sync();

        // Having chosen this one, move on to the next.
        if (box.checked && picked.length < size && slides[i + 1]) {
            setTimeout(() => show(slides[i + 1]), 280);
        }
    }));

    sync();
    refreshRail();
})();
</script>
@endpush
