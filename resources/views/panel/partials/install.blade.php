{{-- Offers to put the panel on the phone's home screen. Android and desktop Chrome
     hand the page a real install prompt; iPhone has none, so it gets Safari's own
     steps instead of a button that cannot work. --}}
<div class="install-scrim" data-install-scrim hidden></div>
<section class="install-sheet" data-install data-snooze-days="14" data-delay="4"
         data-installed="{{ __('panel.install.installed') }}"
         role="dialog" aria-modal="true" aria-labelledby="install-title" hidden>
    <span class="install-sheet__grip" aria-hidden="true"></span>

    <div class="install-sheet__head">
        <img src="{{ asset('assets/brand/icon-192-maskable.png') }}" alt="" width="48" height="48">
        <div>
            <h2 id="install-title">{{ __('panel.install.title') }}</h2>
            <p>{{ __('panel.install.body') }}</p>
        </div>
    </div>

    <div data-install-native hidden>
        <button class="btn-p btn-p--block" type="button" data-install-go>{{ __('panel.install.cta') }}</button>
    </div>

    <div data-install-ios hidden>
        <p class="install-sheet__eyebrow">{{ __('panel.install.iosTitle') }}</p>
        <ol class="install-steps">
            <li><span>{{ __('panel.install.iosStep1') }}</span></li>
            <li><span>{{ __('panel.install.iosStep2') }}</span></li>
            <li><span>{{ __('panel.install.iosStep3') }}</span></li>
        </ol>
        <p class="install-sheet__note">{{ __('panel.install.iosNote') }}</p>
    </div>

    <button class="btn-p btn-p--ghost btn-p--block" type="button" data-install-later>{{ __('panel.install.later') }}</button>
</section>
