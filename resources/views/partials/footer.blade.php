@php
    use App\Support\Nav;
    $categories = app(\App\Services\Overzaki\CatalogService::class)->categoriesWithCounts();
    $phone = $contact['phone'];
    $whatsapp = $contact['whatsapp'];
@endphp

<footer class="footer">
    <div class="container">
        <div class="footer__grid">

            <div class="footer__brand">
                <img src="{{ asset(config('brand.logo.full_cream')) }}"
                     alt="{{ __('storefront.brand.name') }}" width="562" height="493" loading="lazy">
                <p class="footer__about">{{ __('storefront.footer.about') }}</p>

                <div class="socials">
                    <a href="{{ $social['instagram'] }}" target="_blank" rel="noopener"
                       aria-label="Instagram"><x-icon name="instagram" size="18"/></a>
                    <a href="{{ $social['tiktok'] }}" target="_blank" rel="noopener"
                       aria-label="TikTok"><x-icon name="tiktok" size="18"/></a>
                    <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"
                       aria-label="{{ __('storefront.content.whatsapp') }}"><x-icon name="whatsapp" size="18"/></a>
                </div>
            </div>

            <div>
                <h2 class="footer__title">{{ __('storefront.footer.shop') }}</h2>
                <ul class="footer__links">
                    <li><a href="{{ Nav::url('products') }}">{{ __('storefront.listing.allProducts') }}</a></li>
                    @foreach (array_slice($categories, 0, 5) as $category)
                        <li><a href="{{ Nav::url('categories/'.$category['slug']) }}">{{ $category['name'] }}</a></li>
                    @endforeach
                    <li><a href="{{ Nav::url('offers') }}">{{ __('storefront.nav.offers') }}</a></li>
                </ul>
            </div>

            <div>
                <h2 class="footer__title">{{ __('storefront.footer.service') }}</h2>
                <ul class="footer__links">
                    <li><a href="{{ Nav::url('about-us') }}">{{ __('storefront.nav.about') }}</a></li>
                    <li><a href="{{ Nav::url('contact-us') }}">{{ __('storefront.nav.contact') }}</a></li>
                    <li><a href="{{ Nav::url('shipping') }}">{{ __('storefront.content.shippingTitle') }}</a></li>
                    <li><a href="{{ Nav::url('returns') }}">{{ __('storefront.content.returnsTitle') }}</a></li>
                    <li><a href="{{ Nav::url('privacy') }}">{{ __('storefront.content.privacyTitle') }}</a></li>
                    <li><a href="{{ Nav::url('terms') }}">{{ __('storefront.content.termsTitle') }}</a></li>
                </ul>
            </div>

            <div>
                <h2 class="footer__title">{{ __('storefront.footer.contact') }}</h2>
                <ul class="footer__contact">
                    <li>
                        <x-icon name="whatsapp" size="16"/>
                        <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">
                            <span class="ltr-num">+{{ $whatsapp }}</span>
                        </a>
                    </li>
                    <li>
                        <x-icon name="phone" size="16"/>
                        <a href="tel:{{ $phone }}"><span class="ltr-num">{{ $phone }}</span></a>
                    </li>
                    <li>
                        <x-icon name="pin" size="16"/>
                        <span>{{ $locale === 'ar' ? config('brand.country.name_ar') : config('brand.country.name_en') }}</span>
                    </li>
                </ul>

                <h2 class="footer__title" style="margin-block-start:var(--space-6)">
                    {{ __('storefront.footer.newsletter') }}
                </h2>
                <p class="footer__about" style="margin-block-end:var(--space-3)">
                    {{ __('storefront.footer.newsletterText') }}
                </p>
                <form method="POST" action="{{ Nav::url('newsletter') }}" style="display:flex;gap:var(--space-2)">
                    @csrf
                    <label class="visually-hidden" for="newsletter-email">{{ __('storefront.footer.emailPlaceholder') }}</label>
                    <input class="input" id="newsletter-email" type="email" name="email" required
                           value="{{ old('email') }}"
                           placeholder="{{ __('storefront.footer.emailPlaceholder') }}"
                           @error('newsletter') aria-invalid="true" aria-describedby="newsletter-error" @enderror
                           style="min-block-size:44px;background:transparent;border-color:var(--line-on-dark);color:var(--cream-400)">
                    <button class="btn btn--on-dark btn--sm" type="submit">{{ __('storefront.actions.subscribe') }}</button>
                </form>
                @if (session('newsletter'))
                    <p class="footer__about" role="status" style="color:var(--gold-300);margin-block-start:var(--space-2)">
                        {{ session('newsletter') }}
                    </p>
                @endif

                {{-- A rejected address has to say so; silence reads as success. --}}
                @error('newsletter')
                    <p class="footer__about" role="alert" id="newsletter-error"
                       style="color:#e9a3a3;margin-block-start:var(--space-2)">{{ $message }}</p>
                @enderror
                @error('email')
                    <p class="footer__about" role="alert"
                       style="color:#e9a3a3;margin-block-start:var(--space-2)">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="footer__bottom">
            <p>© {{ date('Y') }} {{ __('storefront.brand.name') }} — {{ __('storefront.footer.rights') }}</p>

            <div class="payments">
                <span class="visually-hidden">{{ __('storefront.footer.payments') }}</span>
                <span class="badge badge--quiet">KNET</span>
                <span class="badge badge--quiet">VISA</span>
                <span class="badge badge--quiet">Apple&nbsp;Pay</span>
            </div>

            <span class="lang">
                <a href="{{ Nav::switchTo('ar-KW') }}" aria-current="{{ $localeCode === 'ar-KW' ? 'true' : 'false' }}"><span>العربية</span></a>
                <a href="{{ Nav::switchTo('en-KW') }}" aria-current="{{ $localeCode === 'en-KW' ? 'true' : 'false' }}"><span>English</span></a>
            </span>
        </div>
    </div>
</footer>
