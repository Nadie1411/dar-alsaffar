#!/usr/bin/env bash
# Runs on the VPS, from the app checkout, after the code has been updated.
#   ./scripts/deploy.sh [git-sha]
# Builds an image tagged with the commit, migrates, swaps the container and
# health-checks it. On failure it rolls back to the previously running image.
set -euo pipefail

cd "$(dirname "$0")/.."

SHA="${1:-$(git rev-parse HEAD)}"
TAG="${SHA:0:12}"
PORT="${APP_PORT:-8095}"
COMPOSE="docker compose -f docker-compose.prod.yml"
STATE_FILE=".deployed_tag"
PREVIOUS_TAG="$(cat "$STATE_FILE" 2>/dev/null || true)"

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

# shellcheck source=scripts/deploy-lib.sh
. "$(dirname "$0")/deploy-lib.sh"

[ -f .env ] || { echo "Missing .env — create it from .env.example first." >&2; exit 1; }

# Settings that arrive from GitHub (repository variables and secrets) rather than
# from a file on the server. All are optional; with none set, this is a plain deploy.
#   STORE_BACKEND          local | overzaki — which shop the site runs
#   PANEL_OWNER_EMAIL      with PANEL_OWNER_PASSWORD: creates the first panel owner
#   PANEL_OWNER_PASSWORD   (only while no owner exists; never changes an existing one)
#   PANEL_OWNER_NAME
if [ -n "${STORE_BACKEND:-}" ] && ! valid_backend "$STORE_BACKEND"; then
    echo "STORE_BACKEND must be 'local' or 'overzaki', not '$STORE_BACKEND'. Nothing was deployed." >&2
    exit 1
fi

mkdir -p storage/app/public storage/framework/{cache/data,sessions,views} storage/logs public/uploads
[ -f storage/database.sqlite ] || touch storage/database.sqlite

# The container runs as uid 1000; when the deploy runs as another user (e.g. root),
# hand it ownership of the bind-mounted paths so it can write DB, logs and uploads.
chown -R 1000:1000 storage public/uploads .env 2>/dev/null || true

# What .env held before this run, so a failed deploy can put the site back as it was.
cp .env .env.predeploy

log "Building image dar-alsaffar:$TAG"
docker build --pull -t "dar-alsaffar:$TAG" -t dar-alsaffar:latest .

log "Backing up the SQLite database"
# The database runs in WAL mode: fold what is still in the write-ahead log into
# the main file first, or the copy below would miss the most recent orders.
$COMPOSE run --rm --no-deps -T app php -r '$db = new PDO("sqlite:/app/storage/database.sqlite"); $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");' || true
cp storage/database.sqlite "storage/database.sqlite.bak"

log "Running migrations"
IMAGE_TAG="$TAG" $COMPOSE run --rm --no-deps app php artisan migrate --force

if [ -n "${PANEL_OWNER_EMAIL:-}" ] && [ -n "${PANEL_OWNER_PASSWORD:-}" ]; then
    log "Making sure the panel has an owner"
    # The password goes in on standard input, so it is on no command line and in no log.
    printf '%s' "$PANEL_OWNER_PASSWORD" | IMAGE_TAG="$TAG" $COMPOSE run --rm --no-deps -T app \
        php artisan store:admin "$PANEL_OWNER_EMAIL" --name="${PANEL_OWNER_NAME:-Owner}" --role=owner --bootstrap --password-stdin \
        || echo "Could not set up the owner account; the deploy continues without it." >&2
fi

if [ "${STORE_BACKEND:-}" = "local" ]; then
    log "Filling the catalogue (first time only)"
    if IMAGE_TAG="$TAG" $COMPOSE run --rm --no-deps -T app php artisan store:import-overzaki --if-empty; then
        set_env_value .env STORE_BACKEND local
    else
        echo "The catalogue could not be imported, so the shop stays on its current backend." >&2
    fi
elif [ "${STORE_BACKEND:-}" = "overzaki" ]; then
    set_env_value .env STORE_BACKEND overzaki
fi

log "Starting container"
IMAGE_TAG="$TAG" $COMPOSE up -d --remove-orphans

log "Health check on 127.0.0.1:$PORT/up"
for i in $(seq 1 30); do
    if curl -fsS -o /dev/null "http://127.0.0.1:$PORT/up"; then
        echo "$TAG" > "$STATE_FILE"
        log "Deployed $TAG"
        docker image prune -f >/dev/null
        # Keep the last 3 release images for rollback.
        docker images dar-alsaffar --format '{{.Tag}}' | grep -vE '^(latest|<none>)$' \
            | tail -n +4 | xargs -r -I{} docker rmi "dar-alsaffar:{}" >/dev/null 2>&1 || true
        exit 0
    fi
    sleep 2
done

echo "Health check failed. Last container logs:" >&2
$COMPOSE logs --tail=50 app >&2 || true

if [ -n "$PREVIOUS_TAG" ]; then
    log "Rolling back to $PREVIOUS_TAG"
    # Stop first: the database is in WAL mode, and its -wal/-shm files would
    # otherwise be applied on top of the restored copy.
    $COMPOSE down --remove-orphans || true
    rm -f storage/database.sqlite-wal storage/database.sqlite-shm
    cp storage/database.sqlite.bak storage/database.sqlite
    cp .env.predeploy .env
    IMAGE_TAG="$PREVIOUS_TAG" $COMPOSE up -d --remove-orphans
fi
exit 1
