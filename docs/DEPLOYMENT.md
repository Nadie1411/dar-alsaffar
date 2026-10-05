# Deployment Guide — Dar Alsaffar

This document explains how the site is hosted and **how to deploy a new version**.
Read it once; after that, deployment fits in one sentence:

> **You push to `dev`, open a PR, merge into `main` → the site updates itself.**

---

## 1. Architecture

| Item | Detail |
|---|---|
| Domain | https://daralsaffar.shop |
| Server (VPS) | `184.168.120.225` — Ubuntu 24.04 |
| Application | Laravel storefront — on Overzaki today, on its own backend after the switch-over (section 9) — running in **Docker** (FrankenPHP / PHP 8.4 image) |
| Path on the server | `~/apps/dar-alsaffar` (user `bluecode`) |
| Internal port | The `app` container listens on `127.0.0.1:8095` |
| Scheduler | A second container, `scheduler`, runs `php artisan schedule:work` from the same image (payment reconciliation every 5 minutes, queued emails every minute, a database backup every night) |
| Reverse proxy | The host's **nginx** terminates HTTPS (Let's Encrypt) and forwards to the container |
| Database | **SQLite** (`storage/database.sqlite`) — persisted outside the container |

**Why Docker?** The server has PHP 8.3, but `composer.lock` requires PHP ≥ 8.4.1.
The container ships the right version; nothing to install on the host.

**Persistent data** (never overwritten by a deployment):
- `.env` (production config, secrets such as the MyFatoorah key, the legacy admin password hash)
- `storage/` (SQLite DB, storefront settings, logs)
- `public/uploads/` (pictures uploaded in the panel and the catalogue pictures copied from Overzaki)

Back up all three. The shop's whole state is the SQLite file, `storage/app/storefront-settings.json`
and `public/uploads/`. The database runs in WAL mode (so checkouts, webhooks, the panel and visitors'
sessions can write at once), which means **copying `database.sqlite` by itself can miss the latest
orders** — use the backup command below, not `cp`.

- The `scheduler` container makes a consistent dated copy every night at 03:30 into
  `storage/backups/` (`database-YYYYMMDD-HHMMSS.sqlite` and the matching `settings-….json`) and keeps
  two weeks. Run one by hand any time:
  `docker compose -f docker-compose.prod.yml exec app php artisan store:backup`
- Those copies are on the **same server**. Pull them (and `public/uploads/`) somewhere else on a
  schedule, for example from your own machine or another server:
  `rsync -a bluecode@184.168.120.225:apps/dar-alsaffar/storage/backups/ ~/dar-alsaffar-backups/`
  `rsync -a bluecode@184.168.120.225:apps/dar-alsaffar/public/uploads/ ~/dar-alsaffar-uploads/`
- `scripts/deploy.sh` also keeps the *previous deploy's* copy of the database for its automatic
  rollback; that is a rollback aid, not a backup.
- To restore: stop the containers, put the chosen `database-….sqlite` at `storage/database.sqlite`
  (delete `database.sqlite-wal` and `database.sqlite-shm` beside it), start them again.

---

## 2. The daily workflow (the only thing to remember)

```
1. You work on the  dev  branch
2. git push origin dev            → GitHub runs CI (tests + Docker build)
3. You open a Pull Request        dev → main
4. CI must be green ✅
5. You merge the PR into  main    → AUTOMATIC deployment to the VPS
```

In practice:

```bash
# on your machine, inside the repo
git checkout dev
# ... your changes ...
git add -A
git commit -m "My change"
git push origin dev
```

Then on GitHub: **New Pull Request** (`dev` → `main`), wait for the green check, **Merge**.

### What the automatic deployment does (`.github/workflows/deploy.yml`)
1. Re-runs CI (tests + build).
2. Connects to the VPS over SSH (user `bluecode`).
3. `git reset --hard` to the merged commit  ← **`main` is the source of truth; any change made by hand on the server is overwritten.**
4. Runs `scripts/deploy.sh`, which:
   - builds the new Docker image,
   - **backs up the SQLite database**,
   - runs migrations (`php artisan migrate --force`),
   - swaps the container,
   - **health-checks** `/up`; on failure → **automatic rollback** to the previous version + database restore.
5. Confirms that https://daralsaffar.shop/up responds.

> ⚠️ **Never edit code directly on the server** — the next deployment erases it. Everything goes through Git.

---

## 3. Branch rules

- **`dev`** — working branch. Push freely. Triggers CI.
- **`main`** — production. **Never** push to it directly; only via a merged PR.
- A PR can only be merged when **CI is green**.

---

## 4. GitHub secrets (already configured)

Under *Settings → Secrets and variables → Actions* in the repo:

| Secret | Purpose |
|---|---|
| `VPS_HOST` | Server IP |
| `VPS_USER` | `bluecode` |
| `VPS_SSH_KEY` | SSH deploy private key |

Nothing to do unless the key changes.

---

## 5. Common operations (on the server)

Connect:
```bash
ssh -i <deploy-key> bluecode@184.168.120.225
cd ~/apps/dar-alsaffar
```

All app commands go through `docker compose -f docker-compose.prod.yml`:

```bash
# Container status
docker compose -f docker-compose.prod.yml ps

# App logs
docker compose -f docker-compose.prod.yml logs -f app

# Restart the container
docker compose -f docker-compose.prod.yml restart app

# Set / change the legacy /admin panel password (Overzaki mode only)
docker compose -f docker-compose.prod.yml exec app php artisan store:password

# Create a /panel account, or reset a password (own backend)
docker compose -f docker-compose.prod.yml exec app php artisan store:admin owner@example.com --name="Owner Name"

# Scheduler logs, and a manual payment reconciliation
docker compose -f docker-compose.prod.yml logs -f scheduler
docker compose -f docker-compose.prod.yml exec app php artisan payments:reconcile

# Clear the catalogue cache after a price change on the Overzaki side
docker compose -f docker-compose.prod.yml exec app php artisan cache:clear
```

> The legacy `/admin` panel returns **404** until a password is set (`store:password`), and
> redirects to `/panel` once `STORE_BACKEND=local`. `/panel` returns **404** while the
> backend is `overzaki`.

### Manual deploy / rollback (fallback)
```bash
cd ~/apps/dar-alsaffar
git fetch origin main && git reset --hard origin/main
./scripts/deploy.sh          # build + migrate + swap + health-check + auto-rollback
```

---

## 6. Key environment variables (`.env` on the server)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://daralsaffar.shop
DB_CONNECTION=sqlite
DB_DATABASE=/app/storage/database.sqlite
SESSION_SECURE_COOKIE=true

OVERZAKI_BASE_URL=https://production.overzaki.org/api
OVERZAKI_TENANT_ID=dar-alsaffar.net       # must stay the public hostname
OVERZAKI_CURRENCY_ID=68f0ded46277511861ffca2a   # KWD
OVERZAKI_COUNTRY_ID=660c8364cc6ee3098a612523    # Kuwait
```

Added for the shop's own backend (see section 9 — none of it changes anything while
`STORE_BACKEND=overzaki`):

```dotenv
STORE_BACKEND=overzaki                  # "local" at the switch-over; back to "overzaki" to roll back
STORE_TIMEZONE=Asia/Kuwait              # the shop's own clock for days in reports and lists

# Online payments — MyFatoorah v3. The key is a SECRET: this file only, never Git.
MYFATOORAH_API_KEY=
MYFATOORAH_API_URL=https://api.myfatoorah.com      # Kuwait live. Sandbox: https://apitest.myfatoorah.com
MYFATOORAH_WEBHOOK_SECRET=              # the secure key from MyFatoorah's Webhook Settings
MYFATOORAH_ENABLED=true

# Email — order confirmations, delivery updates, password resets
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="orders@daralsaffar.shop"
MAIL_FROM_NAME="دار الصفار للعطور"
```

`.env` lives **only on the server** (it is not in Git). To add a variable, edit it on the
server, then restart **both** containers — the app caches its configuration when it starts:
`docker compose -f docker-compose.prod.yml restart app scheduler`.

---

## 7. Deployment-related files (in the repo)

| File | Purpose |
|---|---|
| `Dockerfile` | Production image (FrankenPHP + PHP 8.4) |
| `docker-compose.prod.yml` | `app` service (port 8095) and the `scheduler` service, persistent volumes |
| `docker/entrypoint.sh` | Prepares `storage/` and runs `php artisan optimize` at startup |
| `scripts/deploy.sh` | Build + migrate + swap + health-check + rollback |
| `.github/workflows/ci.yml` | Tests + build on `dev` and PRs |
| `.github/workflows/deploy.yml` | Auto-deploy on merge to `main` |
| `deploy/nginx/daralsaffar.shop.conf` | nginx vhost (proxy → 8095), already installed on the host |

---

## 8. First-time server setup (reference — already done)

Only replay this on a brand-new server:
1. `git clone` the repo into `~/apps/dar-alsaffar`.
2. Create `.env` from `.env.example` (production values, `APP_KEY`, Overzaki).
3. `./scripts/deploy.sh` (first build + migrations).
4. **As root/sudo**: install the nginx vhost, then HTTPS —
   ```bash
   cp deploy/nginx/daralsaffar.shop.conf /etc/nginx/sites-available/
   ln -sf /etc/nginx/sites-available/daralsaffar.shop.conf /etc/nginx/sites-enabled/
   nginx -t && systemctl reload nginx
   certbot --nginx -d daralsaffar.shop --redirect
   ```
5. Add the GitHub secrets (`VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`).

---

## 9. Switching the shop to its own backend

The code for it ships switched **off**: with `STORE_BACKEND=overzaki` the site behaves
exactly as before, `/panel` answers 404, and the new tables are simply empty. The owner
decides when to switch. Nothing below needs the site to go down.

### Before the switch

1. **Deploy this version** as usual. Migrations create the new tables.
2. **Import the catalogue** (repeat it as often as wanted until the switch-over):
   ```bash
   docker compose -f docker-compose.prod.yml exec app php artisan store:import-overzaki
   ```
   It prints what it created or updated: categories, products, option groups, delivery
   cities and areas, add-on services, and how many pictures were downloaded.
3. **Create the owner account:**
   `docker compose -f docker-compose.prod.yml exec app php artisan store:admin owner@example.com --name="Owner Name"`
4. **Put the secrets and mail settings in `.env`** (section 6) and restart `app` and `scheduler`.
   Rotate the MyFatoorah key first if it was ever pasted into a chat or an email.
   **Or** leave the MyFatoorah key out of `.env` and paste it after the switch, as an owner, under
   *Payments → Manage payment keys* in `/panel` (it is stored encrypted, shown to nobody, asks for your
   password, and what is saved there wins over `.env`). Until it is there, checkout offers cash on
   delivery only. The panel is not reachable before the switch, so for the rehearsal in step 6 use `.env`.
5. **In the MyFatoorah dashboard:** Integration Settings → Webhook Settings → version
   **V2**, URL `https://daralsaffar.shop/api/webhooks/myfatoorah`, enable the secure key
   and copy it into `MYFATOORAH_WEBHOOK_SECRET`; make sure the methods wanted (KNET,
   cards, Apple Pay, Google Pay) are enabled for the account. The panel's
   *Payments → Test the MyFatoorah connection* lists what the account has enabled.
6. **Rehearse on a copy**, with the sandbox key, before real customers see anything:
   ```bash
   cp -r storage /tmp/rehearsal-storage
   docker compose -f docker-compose.prod.yml run --rm -p 127.0.0.1:8096:8080 \
     -v /tmp/rehearsal-storage:/app/storage \
     -e STORE_BACKEND=local -e MYFATOORAH_API_URL=https://apitest.myfatoorah.com \
     -e MYFATOORAH_API_KEY=<sandbox key> app frankenphp run --config /etc/frankenphp/Caddyfile
   ```
   Place a cash order and a sandbox card order, move them along in `/panel`, and read the
   emails (with `MAIL_MAILER=log` they are in `storage/logs`). The sandbox needs a public
   https address for its return page and webhook: set `MYFATOORAH_CALLBACK_URL` to a tunnel.
7. **Set what Overzaki holds that is not imported:** the delivery fee, any free-delivery
   threshold and any minimum order (*Delivery* in the panel — the defaults are KWD 2, none,
   none, which is what every one of the 217 areas charged), the contact details and About
   copy (*Content & settings*), and any offer worth keeping as a voucher. (On 5 October 2026
   the only public offer on Overzaki was `off20`, which had ended on 12 September.)

### The switch

1. In `.env` set `STORE_BACKEND=local` and, with the live key in place,
   `MYFATOORAH_API_URL=https://api.myfatoorah.com`.
2. `docker compose -f docker-compose.prod.yml restart app scheduler`
3. Check `/panel/login`, the storefront, and place one real cash order and cancel it.
4. Watch `docker compose ... logs -f scheduler` and the panel's *Payments* page for the first
   days — anything marked "needs review" is a payment a person must look at.

### Switching from GitHub, with no access to the server

Everything in "The switch" can be done from the repository's **Settings → Secrets and variables
→ Actions**, then **Actions → Deploy production → Run workflow** (or by merging to `main`).
The deploy job hands these to the server on standard input, so they appear in no log:

| Name | Kind | Effect |
| --- | --- | --- |
| `STORE_BACKEND` | variable | `local` fills the catalogue from Overzaki once (only when it is empty), then makes the shop run on its own backend. `overzaki` puts it back. Unset = no change. |
| `PANEL_OWNER_EMAIL` | secret | Address of the first panel owner. |
| `PANEL_OWNER_PASSWORD` | secret | Their password, 10+ characters with letters and numbers. Choose it yourself. |
| `PANEL_OWNER_NAME` | variable | Their name (default "Owner"). |

The owner is created only while no active owner exists, so later deployments never touch the
account and a password changed in the panel stays changed. Another owner can reset a
password in the panel; with server access, `store:admin <email>` does it. The catalogue import runs
before the flip, and if it fails the shop stays on its current backend. A failed health check
puts back the previous image, the database and the previous `.env`.

Then sign in at `https://daralsaffar.shop/panel` and paste the MyFatoorah key on
**Payments → Manage payment keys** (it is stored encrypted and never shown back).

### What does not carry over

- **Customer accounts.** Overzaki cannot export passwords, so customers register again
  (their addresses are saved with their next order). Shoppers checking out as guests are
  unaffected.
- **Past orders** stay in Overzaki; the own backend starts at `DS-100001`.
- **Overzaki promotions** of the "buy X get Y" and automatic kinds. The panel has product
  discounts, quantity tiers and percentage / fixed / free-delivery vouchers.

### Rolling back

Set `STORE_BACKEND=overzaki` and restart `app` and `scheduler`. The storefront is on Overzaki
again at once. Orders placed on the own backend stay in its database (they are not pushed to
Overzaki): list them in `/panel` (Orders → export) or from `storage/database.sqlite`, and
fulfil them as usual. `/panel` answers 404 again until the next switch.

---

## Quick summary

- **Deploying = merging a PR into `main`.** Nothing else.
- **Never edit directly on the server** (overwritten on the next deploy).
- SQLite DB + `storage/` + `.env` are **persistent**.
- `/admin` (legacy, Overzaki mode) requires `php artisan store:password`; `/panel` (own backend) requires `php artisan store:admin`.
- Back up `storage/` (nightly copies are in `storage/backups/`), `public/uploads/` and `.env` — the shop's whole state — somewhere that is not this server.
- If something breaks, the deployment **rolls back on its own**.
