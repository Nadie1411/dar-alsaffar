@php use App\Support\Nav; @endphp

@php
    $settings = app(\App\Services\Settings::class);

    // Copy entered in the control panel wins; otherwise the pop-up simply
    // mirrors whatever offer is live in the Overzaki dashboard.
    $title    = $settings->get('popup.title', '') ?: ($offer['headline'] ?? null);
    $body     = $settings->get('popup.body', '') ?: ($offer['scope']['label'] ?? null);
    $image    = $settings->get('popup.image', '');
    $ctaLabel = $settings->get('popup.cta_label', '') ?: __('storefront.promo.seeOffers');
    $ctaPath  = $settings->get('popup.cta_path', '') ?: 'offers';
    $delay    = $settings->int('popup.delay', 6);
    $snooze   = $settings->int('popup.snooze_days', 7);

    $suppressed = collect(config('promo.popup.except'))->contains(fn ($p) => \App\Support\Nav::isActive($p));
    $show = $settings->bool('popup.enabled') && $title && ! $suppressed;

    // Dismissals are keyed to the copy, so editing the campaign shows it again
    // to people who already dismissed the previous one.
    $key = substr(sha1((string) $title.($offer['id'] ?? '')), 0, 12);
@endphp

@if ($show)
    <div class="promo-popup" id="promo-popup" role="dialog" aria-modal="true"
         aria-labelledby="promo-popup-title"
         data-promo-popup
         data-key="{{ $key }}"
         data-delay="{{ $delay }}"
         data-snooze="{{ $snooze }}"
         hidden>

        <div class="promo-popup__card">
            <button type="button" class="promo-popup__close" data-promo-close
                    aria-label="{{ __('storefront.popup.close') }}">
                <x-icon name="close" size="18"/>
            </button>

            @if ($image)
                <div class="promo-popup__media">
                    <img src="{{ \Illuminate\Support\Str::startsWith($image, ['http', '//']) ? $image : asset($image) }}" alt="" loading="lazy" decoding="async">
                </div>
            @endif

            <div class="promo-popup__body">
                <p class="section-heading__eyebrow" style="justify-content:center">
                    {{ __('storefront.promo.eyebrow') }}
                </p>

                <h2 class="promo-popup__title" id="promo-popup-title">{{ $title }}</h2>

                @if ($body)
                    <p class="promo-popup__text">{{ $body }}</p>
                @endif

                @if (! empty($offer['code']))
                    <p style="margin-block-end:var(--space-5)">
                        <x-offer-code :code="$offer['code']" style="border-color:var(--gold-500);color:var(--gold-600)"/>
                    </p>
                @endif

                <a class="btn btn--block" href="{{ Nav::url($ctaPath) }}">{{ $ctaLabel }}</a>

                <p style="margin-block-start:var(--space-3)">
                    <button type="button" class="link-underline muted" data-promo-close>
                        {{ __('storefront.popup.noThanks') }}
                    </button>
                </p>
            </div>
        </div>
    </div>
@endif
