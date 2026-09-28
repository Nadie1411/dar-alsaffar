# دار الصفار للعطور — Storefront

A Laravel storefront for Dar Alsaffar Perfumes, Arabic-first and RTL-native,
running against the store's existing **Overzaki** backend.

---

## What this is (and what it is not)

This application is **the presentation layer only**. Products, prices,
discounts, promotions, stock, customers, addresses, orders and payments all
live in Overzaki and are reached over its REST API.

Nothing here computes money. Every figure a shopper sees — line price after
discount, subtotal, delivery, VAT, gift eligibility, order total — comes back
from Overzaki's own cart checker, which is the same engine that prices the
order at checkout. That is deliberate: a second pricing implementation could
disagree with the real one and charge the wrong amount.

| Layer | Where it lives |
|---|---|
| Catalogue, stock, pricing | Overzaki (`production.overzaki.org/api`) |
| Promotions & vouchers | Overzaki dashboard |
| Customers, orders, payments | Overzaki |
| Design, layout, copy, UX | **This repository** |

The previous storefront was a Next.js app hosted by Overzaki on Vercel. This
replaces that front end while keeping the same backend, and keeps the same URL
shape so existing links and search rankings survive.

---

## Store control panel

A small settings screen at **`/admin`** for the shop team. It covers only what
this application owns — the announcement strip, the pop-up's own copy and the
add-to-home-screen prompt. Products, prices, offers, delivery and orders stay
in the Overzaki dashboard, so nothing here can contradict what a shopper is
charged.

It is **mobile-first**: full-width controls, 48px targets and a sticky save
button, because the team edits it from a phone more often than a desk.

### Turning it on

```bash
php artisan store:password
```

Until a password hash is configured, `/admin` returns **404** — not a login
form. An unconfigured deployment has no door to knock on. The password is
hashed into `.env`; the plain password is never stored.

Sign-in is rate limited per IP (5 attempts a minute by default,
`ADMIN_THROTTLE`), and the panel is `noindex, nofollow`.

### What it does

| Section | Controls |
|---|---|
| العروض الفعّالة الآن | A live read of offers running in Overzaki — read only |
| شريط العروض | Show/hide the announcement bar |
| النافذة المنبثقة | Title, body, image, button label and destination, delay, snooze |
| الدفع عند الاستلام | Show/hide cash on delivery at checkout |
| إضافة الموقع للهاتف | Show/hide the install prompt, and its delay |
| تحديث بيانات المتجر | Clears the catalogue cache after a price edit upstream |

Leaving the pop-up title empty makes it mirror whatever offer is live, so it
needs no editing between campaigns.

Cash on delivery is offered only when this switch **and** Overzaki's own
`availableCashOnDelivery` agree — offering it when the platform has it off
would fail the order after the shopper had picked it.

### Order alert

`/admin/orders` polls the store's order feed and rings continuously until
someone presses "Seen". The tone is synthesised with the Web Audio API — no
audio file to ship, and it loops cleanly.

Two constraints worth knowing, both browser-imposed rather than ours: audio
cannot start without a user gesture, so the page arms the sound on an explicit
press; and it only rings while the page is open. The tab title blinks as well,
and the banner pulses, so the alert never depends on sound alone. For alerts
that reach a phone, use Overzaki's WhatsApp order notifications.

The "seen" marker is stored server side, so acknowledging on one device
silences the others.

> This reads `/orders/search`, which Overzaki currently serves **without
> authentication**. That is a flaw in their platform worth reporting; if they
> close it, this screen needs proper API credentials and `/orders/all`.

Settings are stored in `storage/app/storefront-settings.json` — one file, no
migration to run, easy to back up. Uploaded pop-up images go to
`public/uploads/` with randomised filenames.

---

## Requirements

- PHP **8.2+** (developed on 8.5)
- Composer 2
- No Node.js, no build step — CSS and JS are hand-authored and served as-is

## Running locally

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Then open <http://localhost:8000/ar-KW>.

### Environment

```dotenv
OVERZAKI_BASE_URL=https://production.overzaki.org/api
OVERZAKI_TENANT_ID=dar-alsaffar.net      # the tenant is resolved by domain
OVERZAKI_CURRENCY_ID=68f0ded46277511861ffca2a   # KWD
OVERZAKI_COUNTRY_ID=660c8364cc6ee3098a612523    # Kuwait
```

`OVERZAKI_TENANT_ID` must stay the public hostname — Overzaki resolves the
store from it.

---

## URLs

Locale-prefixed exactly as before, so nothing that is already indexed breaks:

```
/ar-KW                      /en-KW
/ar-KW/products             /ar-KW/products/{slug}
/ar-KW/categories           /ar-KW/categories/{slug}
/ar-KW/offers               /ar-KW/best-sellers
/ar-KW/packages             /ar-KW/packages/{slug}
/ar-KW/search               /ar-KW/cart          /ar-KW/checkout
/ar-KW/wishlist             /ar-KW/account/*
/ar-KW/about-us             /ar-KW/contact-us
/ar-KW/shipping  /ar-KW/returns  /ar-KW/privacy  /ar-KW/terms
```

`/` redirects to `/ar-KW`. The old unprefixed paths 301 to their Arabic
equivalents. The language switcher keeps the shopper on the same page.

---

## Architecture

```
app/
  Services/Overzaki/
    OverzakiClient.php     HTTP transport (tenant + currency headers, pooling)
    CatalogService.php     products, categories, search, facets
    CartService.php        session basket → API-priced quote
    CartQuote.php          read-only view of a priced cart
    OrderService.php       address data, payment methods, order placement
    AuthService.php        customer sessions (token in PHP session)
    WishlistService.php    guest + signed-in wishlists
    PromotionService.php   live offers from the dashboard
    DTO/Product.php        view model over a product document
  Support/
    Loc.php   localised strings from the API's {ar, en} maps
    Money.php KWD formatting
    Nav.php   locale-aware URLs
resources/views/
  layouts/  partials/  components/  pages/
public/assets/
  css/  tokens → base → components → pages
  js/   store.js (UI), promo.js (offers, pop-up, install)
```

### Design system

`public/assets/css/tokens.css` holds everything: colour, type scale, spacing,
radii, shadows, motion. The palette is taken from the brand mark — emerald
`#00603a` and cream `#f3e8c8` sampled from the logo artwork — with charcoal,
warm ivory and a champagne gold used strictly as an accent.

All directional CSS uses **logical properties** (`margin-inline-start`,
`inset-inline-end`, …) so Arabic is the native layout and English is the
mirror, not a flipped afterthought.

### Typography

- **Amiri** — Arabic display headings
- **Cormorant Garamond** — Latin display
- **IBM Plex Sans Arabic** — UI and body, both scripts

> Note: `ch` is a poor measure for Arabic (the zero glyph is narrow), so display
> measures are `em`-based. See the comment in `pages.css`.

---

## Operating the store

Day-to-day merchandising happens in the **Overzaki dashboard**, not in this
code. See **[docs/دليل-التشغيل.md](docs/دليل-التشغيل.md)** for step-by-step
Arabic instructions covering:

- اشتري ٢ والثالث هدية (buy 2 get 1 free)
- خصومات بنسبة أو مبلغ ثابت
- توصيل مجاني فوق مبلغ معيّن
- تغليف الهدايا بسعر إضافي
- رسوم التوصيل حسب المنطقة

The storefront surfaces whatever is live — an expired or switched-off offer
simply stops appearing, with no code change.

### Things this repo does control

`config/promo.php` — the announcement strip, the pop-up (timing, snooze,
optional custom copy) and the add-to-home-screen prompt.

Leaving the pop-up's `title` as `null` makes it mirror whatever offer is
currently live in the dashboard, so it needs no editing between campaigns.

---

## Add to home screen (PWA)

`public/manifest.webmanifest` + `public/sw.js`.

The service worker caches **static assets only**. It never caches a page, the
cart, or any API response — prices and stock must always be live.

**Android / Chrome / Edge:** a real one-tap install via `beforeinstallprompt`.

**iPhone / iPad:** iOS exposes no install API at all. Apple requires the user
to go through *Share → Add to Home Screen*, and no website can automate or
shortcut it. The prompt therefore shows the actual Safari steps on iOS rather
than a button that cannot work.

---

## Accessibility

Semantic landmarks, one `<h1>` per page with no heading-level jumps, labelled
form controls, visible focus rings, `prefers-reduced-motion` honoured
throughout, and state never carried by colour alone (discounts, errors and
progress all carry text).

Text colours meet WCAG AA (4.5:1) against their backgrounds — `--ink-400` and
`--gold-600` are tuned specifically to clear that bar on ivory.

---

## Caching

Catalogue 5 min, taxonomy and add-ons 15 min, offers 5 min. Clear with:

```bash
php artisan cache:clear
```

Products priced entirely through options (oud sold by the tola) need a detail
call each; those are fetched concurrently via `OverzakiClient::pool()` so the
whole catalogue still loads in about a second.

---

## Deployment

The production site runs in **Docker** on the VPS behind the host's nginx, and
deploys automatically: **push to `dev`, open a PR, merge into `main` → the VPS
updates itself** (build, migrate, health-check, auto-rollback).

**➡️ Full workflow, server operations and env reference: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**

Quick facts:

- Live at https://daralsaffar.shop — served over **HTTPS** (required
  for the service worker and the install prompt).
- CI runs on `dev` and every PR; merging to `main` triggers the deploy
  (`.github/workflows/deploy.yml`).
- Persistent, never overwritten: `.env`, `storage/` (SQLite DB), `public/uploads/`.
- Never edit code directly on the server — the next deploy overwrites it.

For a plain (non-Docker) host, the app is a standard Laravel deployment: point
the web root at `public/`, set `APP_ENV=production` and `APP_DEBUG=false`, then
`composer install --no-dev --optimize-autoloader` and
`php artisan config:cache && php artisan route:cache && php artisan view:cache`.
