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

[ -f .env ] || { echo "Missing .env — create it from .env.example first." >&2; exit 1; }

mkdir -p storage/app/public storage/framework/{cache/data,sessions,views} storage/logs public/uploads
[ -f storage/database.sqlite ] || touch storage/database.sqlite

# The container runs as uid 1000; when the deploy runs as another user (e.g. root),
# hand it ownership of the bind-mounted paths so it can write DB, logs and uploads.
chown -R 1000:1000 storage public/uploads .env 2>/dev/null || true

log "Building image dar-alsaffar:$TAG"
docker build --pull -t "dar-alsaffar:$TAG" -t dar-alsaffar:latest .

log "Backing up the SQLite database"
cp storage/database.sqlite "storage/database.sqlite.bak"

log "Running migrations"
IMAGE_TAG="$TAG" $COMPOSE run --rm --no-deps app php artisan migrate --force

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
    cp storage/database.sqlite.bak storage/database.sqlite
    IMAGE_TAG="$PREVIOUS_TAG" $COMPOSE up -d --remove-orphans
fi
exit 1
