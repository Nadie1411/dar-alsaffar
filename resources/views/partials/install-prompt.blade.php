@php $settings = app(\App\Services\Settings::class); @endphp

@if ($settings->bool('install.enabled'))
    {{-- Android and desktop Chrome fire `beforeinstallprompt`, so those get a
         genuine one-tap install. iOS gives no such API at all, so iPhone users
         get the real Safari steps instead of a button that cannot work. --}}
    <div class="sheet" id="install-prompt" data-panel="install-prompt"
         data-install-prompt
         data-delay="{{ $settings->int('install.delay', 12) }}"
         data-snooze="{{ (int) config('promo.install.snooze_days', 14) }}"
         role="dialog" aria-modal="true" aria-labelledby="install-title" hidden>

        <span class="sheet__grip" aria-hidden="true"></span>

        <div class="sheet__body">
            <div class="install-card" style="margin-block-end:var(--space-5)">
                <img class="install-card__icon" src="{{ asset('assets/brand/icon-192-maskable.png') }}"
                     alt="" width="44" height="44">
                <div>
                    <p class="promo-meter__title" id="install-title">{{ __('storefront.pwa.installTitle') }}</p>
                    <p class="promo-meter__note">{{ __('storefront.pwa.installBody') }}</p>
                </div>
            </div>

            {{-- Shown when the browser supports a real install prompt. --}}
            <div data-install-native hidden>
                <button class="btn btn--lg btn--block" type="button" data-install-go>
                    <x-icon name="plus" size="16"/>
                    {{ __('storefront.pwa.installCta') }}
                </button>
            </div>

            {{-- Shown on iOS, where the user must do it through Share. --}}
            <div data-install-ios hidden>
                <p class="section-heading__eyebrow">{{ __('storefront.pwa.iosTitle') }}</p>
                <ol class="install-steps">
                    <li><span>{{ __('storefront.pwa.iosStep1') }}</span></li>
                    <li><span>{{ __('storefront.pwa.iosStep2') }}</span></li>
                    <li><span>{{ __('storefront.pwa.iosStep3') }}</span></li>
                </ol>
                <p class="field__hint">{{ __('storefront.pwa.iosNote') }}</p>
            </div>
        </div>

        <div class="sheet__foot" style="grid-template-columns:1fr">
            <button class="btn btn--ghost" type="button" data-install-later>
                {{ __('storefront.pwa.later') }}
            </button>
        </div>
    </div>
@endif
