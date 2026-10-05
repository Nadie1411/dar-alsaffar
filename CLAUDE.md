<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

=== dar al saffar — project rules ===

# Dar Al Saffar storefront

An Arabic-first, RTL-native storefront for a Kuwaiti perfume house. It runs in
one of two ways, chosen by `STORE_BACKEND` (`config/store.php`):

- `overzaki` (the default, and what production ran until the switch-over): the
  shop lives in Overzaki and this app is its presentation layer.
- `local`: the shop lives **in this app** — its own database, an admin panel at
  `/panel`, and payments taken directly through MyFatoorah.

Every page asks one of seven contracts in `app/Contracts/Store` (Catalog, Cart,
Orders, Customers, Wishlist, Promotions, Inbox) for what it needs.
`StoreServiceProvider` binds the Overzaki or the local implementation, so the
views are identical either way. A test fails if a contract has no local
implementation. Flipping `STORE_BACKEND` back is the rollback.

## The rule that matters most

**Money is computed in exactly one place.**

- In `overzaki` mode never compute money: every figure comes back from
  Overzaki's cart checker (`CartService::quote()` → `CartQuote`).
- In `local` mode the one place is `App\Services\Store\Pricing\PricingEngine`.
  Everything is **whole fils** (1 KWD = 1,000 fils), never a float. The cart,
  the checkout summary and the placed order all read the same `PricedCart`; no
  controller, view or Blade partial does arithmetic on prices. It is
  parity-tested against Overzaki's real cart checker.
- Whatever staff type as an amount goes through `Money::parseFils()` (digit by
  digit — Arabic digits and a decimal comma included) and the `Dinars` rule.
- `PromotionService` / `LocalPromotions` only *read* offers.

A second pricing implementation anywhere else could disagree with the engine
that actually charges the customer.

## Payments

Online payment is MyFatoorah's v3 API, hosted page. An order is paid only
after MyFatoorah **confirms** it: the webhook and the return page are never
trusted, every outcome is re-read with `GET /v3/payments/{id}`, and the amount
is checked against the order. The API key is a secret: it lives in the
server's `.env` (`MYFATOORAH_API_KEY`) or, saved by an owner on the owner-only
*Payments → Payment keys* page, encrypted in `gateway_credentials`. All reads go
through `GatewayConfig` (panel value first, then `.env`). It is never committed,
logged, flashed into the session, put in the activity log, shown back by any
page or sent to a browser — keep it that way (`dontFlash`, `#[Hidden]`, tests
that search every page for it). Tests never reach MyFatoorah (`Http::fake` and
`preventStrayRequests`), and nobody — including an AI assistant — types a live
key into a field on someone's behalf.

## Stack notes

- **No Node, no build step.** CSS and JS are hand-authored in `public/assets/`
  and served directly. Do not introduce Vite, Tailwind or a bundler — the site
  must deploy to plain PHP hosting.
- Asset URLs go through `App\Support\Asset::url()` for cache busting.
- Design tokens live in `public/assets/css/tokens.css`. Load order is
  tokens → base → components → pages. The admin panel has its own
  `panel.css` / `panel.js` on top of the tokens.
- Uploads go through `App\Services\Store\Uploads` (random names, a short
  allow-list of types, never deleted while another row still points at them).
  HTML typed by staff goes through `App\Support\Html::clean()`.
- Staff-facing exports go through `App\Support\Csv` (formula-injection safe).

## RTL and Arabic

- Arabic is the default; English mirrors it. Use **logical properties**
  (`margin-inline-start`, `inset-inline-end`) — never `left`/`right`.
- `ch` is a poor measure for Arabic (the zero glyph is narrow). Use `em` for
  display measures.
- All user-facing strings go in `lang/{ar,en}/storefront.php` (the shop) or
  `lang/{ar,en}/panel.php` (the admin panel). Never hardcode copy in a Blade
  file. `PanelLanguageTest` fails when a key is missing in either language.
- Localised API values (`{ar, en}` maps) go through `App\Support\Loc::text()`.

## Content integrity

Do not invent brand claims, product copy, prices, delivery promises or
policies. Where the business has not published content, the layout ships with
a clearly marked placeholder (`.placeholder-note`) rather than filler. The same
goes for emails: say what happened, promise nothing the shop has not published.

## Scope boundaries

- `/panel` is the staff admin panel for the `local` backend: orders, products,
  categories, add-on services, vouchers, delivery, payments, customers, inbox,
  content, reports, staff and an activity log. Roles (`AdminRole`): Owner,
  Manager, Staff, each limited to the modules `AdminRole::modules()` lists. It
  answers 404 while the backend is `overzaki`. The first owner is created with
  `php artisan store:admin`.
- `/admin` is the old single-password panel (announcement strip, pop-up,
  install prompt, order board). It exists only while the backend is `overzaki`;
  once it is `local` it redirects to `/panel`.
- `php artisan store:import-overzaki` copies the catalogue, options, delivery
  areas and images across; it is safe to repeat until the switch-over.
