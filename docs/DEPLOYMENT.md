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
| Application | Laravel (Overzaki storefront), running in **Docker** (FrankenPHP / PHP 8.4 image) |
| Path on the server | `~/apps/dar-alsaffar` (user `bluecode`) |
| Internal port | The container listens on `127.0.0.1:8095` |
| Reverse proxy | The host's **nginx** terminates HTTPS (Let's Encrypt) and forwards to the container |
| Database | **SQLite** (`storage/database.sqlite`) — persisted outside the container |

**Why Docker?** The server has PHP 8.3, but `composer.lock` requires PHP ≥ 8.4.1.
The container ships the right version; nothing to install on the host.

**Persistent data** (never overwritten by a deployment):
- `.env` (production config + admin password hash)
- `storage/` (SQLite DB, storefront settings, logs)
- `public/uploads/` (admin pop-up images)

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

# Set / change the /admin panel password
docker compose -f docker-compose.prod.yml exec app php artisan store:password

# Clear the catalogue cache after a price change on the Overzaki side
docker compose -f docker-compose.prod.yml exec app php artisan cache:clear
```

> The `/admin` panel returns **404** until a password is set (`store:password`).

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

`.env` lives **only on the server** (it is not in Git). To add a variable,
edit it on the server, then `docker compose -f docker-compose.prod.yml restart app`.

---

## 7. Deployment-related files (in the repo)

| File | Purpose |
|---|---|
| `Dockerfile` | Production image (FrankenPHP + PHP 8.4) |
| `docker-compose.prod.yml` | `app` service, port 8095, persistent volumes |
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

## Quick summary

- **Deploying = merging a PR into `main`.** Nothing else.
- **Never edit directly on the server** (overwritten on the next deploy).
- SQLite DB + `storage/` + `.env` are **persistent**.
- `/admin` requires `php artisan store:password`.
- If something breaks, the deployment **rolls back on its own**.
