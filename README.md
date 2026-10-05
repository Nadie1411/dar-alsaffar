# دار الصفار للعطور — Storefront

A Laravel storefront for Dar Alsaffar Perfumes, Arabic-first and RTL-native.
It runs in **one of two ways**, chosen by a single setting, `STORE_BACKEND`:

| `STORE_BACKEND` | Where the shop lives |
|---|---|
| `overzaki` (default) | The original setup. Products, prices, promotions, customers, orders and payments are in Overzaki and reached over its REST API; this application only presents them. |
| `local` | The shop is **this application**. Its own database holds the products, orders, customers and payments, the team runs it from the admin panel at `/panel`, and shoppers pay **directly through MyFatoorah**. Overzaki is not contacted. |

Every page asks one of seven contracts in `app/Contracts/Store` (catalogue,
cart, orders, customers, wishlist, promotions, inbox) for what it needs, and
`StoreServiceProvider` binds the Overzaki or the local implementation. The
views are identical either way, and flipping the setting back to `overzaki` is
the rollback.

> Production runs `overzaki` until the owner decides to switch. The switch-over
> plan is in **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** (section 9).

## Who computes the money

- **`overzaki` mode never computes money.** Every figure a shopper sees comes
  back from Overzaki's cart checker — the engine that prices the order at
  checkout. A second implementation could disagree and charge the wrong amount.
- **`local` mode computes it in exactly one place:** `PricingEngine`, in whole
  fils (1 KWD = 1,000 fils), never a float. The cart, the checkout summary and
  the placed order all read the same priced basket. It was checked against
  Overzaki's real cart checker on 177 baskets — to the fil, with no mismatches.

| Layer | `overzaki` | `local` |
|---|---|---|
| Catalogue, stock, pricing | Overzaki | this app's database, `PricingEngine` |
| Offers | Overzaki dashboard | product discounts, quantity tiers and vouchers in `/panel` |
| Customers, orders | Overzaki | this app's database |
| Payments | Overzaki | MyFatoorah, directly |
| Design, layout, copy, UX | this repository | this repository |

The previous storefront was a Next.js app hosted by Overzaki on Vercel. This
replaces that front end and keeps the same URL shape, so existing links and
search rankings survive.

---

## Legacy settings panel (`/admin`, while the store runs on Overzaki)

A small settings screen at **`/admin`** for the shop team. It exists only while
`STORE_BACKEND=overzaki`; once the store runs on its own backend `/admin`
redirects to the full panel at `/panel` (next section). It covers only what
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

## The store's own backend (`STORE_BACKEND=local`)

```bash
php artisan migrate
php artisan store:import-overzaki      # copy the catalogue, options, delivery areas and images
php artisan store:admin owner@example.com --name="Owner Name"   # the first panel account
```

- **What the import copies:** categories, products, option groups and values
  (Overzaki shares one group between products; each product gets its own copy),
  quantity-tier discounts, the 217 delivery areas and every picture, which is
  downloaded into `public/uploads/catalog` so the shop no longer depends on
  Overzaki's CDN. It can be run again until the switch-over and refuses to run
  once the backend is `local` (it would overwrite panel edits) unless `--force`.
- **What it does not copy:** customer accounts (Overzaki cannot export
  passwords, so customers register again), past orders (they stay in Overzaki
  unless an export is supplied) and offers (recreate the ones wanted as
  vouchers, product discounts or quantity tiers in the panel).
- **Orders** are numbered `DS-100001`, `DS-100002`, … Stock is taken atomically
  when an order is placed and put back when it is cancelled.
- **Time:** orders are stored in UTC; reports and lists use the shop's own day
  (`STORE_TIMEZONE`, default `Asia/Kuwait`).
- **A scheduler is needed** (`php artisan schedule:work`; the production
  compose file runs it as its own `scheduler` container). It reconciles online
  payments every five minutes and works through queued emails every minute.

## Admin panel (`/panel`)

The staff panel for the `local` backend, Arabic-first with a full English
version (each person picks their language), themed in the shop's emerald and
gold. It answers **404** while the backend is `overzaki`, and everything behind
the sign-in checks the person's role.

| Area | What it does | Owner | Manager | Staff |
|---|---|:-:|:-:|:-:|
| Dashboard | Today's sales and orders, what needs attention, 30-day chart, best sellers | ✓ | ✓ | ✓ (no sales figures) |
| Orders | Filters, search, status changes, internal notes, print, CSV, live new-order alert | ✓ | ✓ | ✓ |
| Customers | Accounts, history, spend, CSV | ✓ | ✓ | ✓ |
| Inbox | Contact messages and newsletter sign-ups | ✓ | ✓ | ✓ |
| Products, Categories, Add-on services | Full catalogue editing: pictures, options, quantity tiers, discounts, stock | ✓ | ✓ | |
| Vouchers | Percentage, fixed and free-delivery codes with limits and dates | ✓ | ✓ | |
| Delivery | Governorates, areas, fees, free-delivery threshold, minimum order | ✓ | ✓ | |
| Payments | MyFatoorah status, attempts, re-check, anomalies, connection test, cash on delivery | ✓ | ✓ | |
| Content & settings | Offer strip, pop-up, hero, About copy, contact details, alerts | ✓ | ✓ | |
| Reports | Sales by period, product, payment method and governorate; CSV | ✓ | ✓ | |
| Activity log | Who did what, and when | ✓ | ✓ | |
| Payment keys | The MyFatoorah key, webhook secret and environment, saved encrypted | ✓ | | |
| Staff | Accounts and roles | ✓ | | |

Security: passwords are hashed and must be 10+ characters with letters and
numbers; sign-in is rate limited per address and IP; an account switched off is
signed out on its very next request; changing a password signs out every other
session; the last active owner cannot be removed or demoted; every change is in
the activity log; uploads are limited to a short list of image types (and MP4)
under random names; HTML typed into a description is cleaned to a handful of
safe tags; CSV exports cannot carry a spreadsheet formula.

All of it is covered by tests (`tests/Feature/Http/Controllers/Panel`), including
that every role sees only its own modules.

## Online payments (MyFatoorah)

Shoppers pay on MyFatoorah's hosted page (KNET, cards, Apple Pay, Google Pay —
whichever the account has enabled), using the **v3 API**.

- **An order is paid only once MyFatoorah confirms it.** The return page and the
  webhook are triggers, not proof: each is followed by `GET /v3/payments/{id}`,
  and the amount and currency are checked against the order. A mismatch, a
  second payment for the same order, or a payment that arrives after
  cancellation is flagged in the panel for a person to decide.
- **Routes:** `GET /{locale}/payment/return` (the shopper comes back here),
  `POST /api/webhooks/myfatoorah` (no session or CSRF; signature checked),
  `GET /{locale}/checkout/pay/{number}` (resume an unpaid order),
  `/{locale}/checkout/pending` and `/{locale}/checkout/failed`.
- **Webhook:** in the MyFatoorah dashboard (Integration Settings → Webhook
  Settings) use **version V2**, the address
  `https://<your-domain>/api/webhooks/myfatoorah`, and enable the secure key —
  put that key in `MYFATOORAH_WEBHOOK_SECRET`. The panel's Payments page shows
  the exact address and a "test the connection" button that lists the methods the
  account has enabled.
- **Environment:** `MYFATOORAH_API_KEY`, `MYFATOORAH_API_URL`
  (`https://api.myfatoorah.com` for Kuwait live, `https://apitest.myfatoorah.com`
  for the sandbox), `MYFATOORAH_WEBHOOK_SECRET`, `MYFATOORAH_ENABLED`, and
  `MYFATOORAH_CALLBACK_URL` only when MyFatoorah must be given a different
  public https host (for example a tunnel while testing).
- **The API key is a secret.** Put it in the server's `.env`, **or** let an owner
  paste it into the panel (*Payments → Manage payment keys*), which stores it
  encrypted with the application key. Either way it is never committed, logged,
  shown back by any page (the form takes a new value or leaves the saved one
  alone), sent to a browser, or written into the session or the activity log.
  Only an owner can open that page, it asks for their password again, and what
  is saved there wins over the `.env` value until it is removed. The environment
  is a list to choose from, never an address to type, because the key is sent to
  whichever one is chosen.
- `php artisan payments:reconcile` (scheduled every five minutes) finds payments
  whose shopper never came back and whose webhook never arrived, and releases the
  order of an invoice that has run out.

## Emails

Queued, so a slow mail server never holds up a checkout, and worked through by the
scheduler. A customer who gave an address gets an order confirmation and, when a
member of staff moves the order, a note that it is out for delivery, delivered or
cancelled; a shop address set under *Content & settings* gets a new-order alert.
Emails are in the language the order was placed in (the staff alert is in
Arabic). Production needs `MAIL_MAILER=smtp` and the provider's details in `.env`
(see `.env.example`); with the default `log` mailer they are written to
`storage/logs` instead of sent.

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
  Contracts/Store/         the seven things a page asks for (catalogue, cart, orders, ...)
  Services/Store/          the own backend: LocalCatalog, LocalCart, LocalOrders, ...
    Pricing/PricingEngine  the one place money is worked out (whole fils)
    Payments/              MyFatoorah client, payment service, webhook signature
    Reports/SalesReport    what the panel's reports and dashboard read
    Import/                store:import-overzaki
  Http/Controllers/Panel/  the admin panel
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
  layouts/  partials/  components/  pages/   the storefront
  panel/  components/panel/                  the admin panel
  emails/                                    order emails
routes/  web.php (storefront)  panel.php (/panel)  api.php (webhooks)
public/assets/
  css/  tokens → base → components → pages;  panel.css for the panel
  js/   store.js (UI), promo.js (offers, pop-up, install), panel.js (panel)
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

While the store runs on Overzaki, day-to-day merchandising happens in the
**Overzaki dashboard**, not in this code. (Once it runs on its own backend all of
it happens in the admin panel at `/panel`.) See **[docs/دليل-التشغيل.md](docs/دليل-التشغيل.md)** for step-by-step
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

In `overzaki` mode: catalogue 5 min, taxonomy and add-ons 15 min, offers 5 min
(the own backend reads its database directly, so there is nothing to wait for).
Clear with:

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
