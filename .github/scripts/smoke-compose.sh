#!/bin/sh
set -eu

# Only disposable CI resources are created; the real deployment is never touched.
export COMPOSE_PROJECT_NAME="identity-smoke-${GITHUB_RUN_ID:?Run only in isolated CI}"
export IDENTITY_IMAGE=identity-service
export IDENTITY_VERSION=smoke
export IDENTITY_BIND_PORT=18081
export APP_URL=https://identity.example.test
export DB_PASSWORD="$(openssl rand -hex 24)"

cleanup() {
    docker compose --profile database --profile redis down --volumes --remove-orphans
}
trap cleanup EXIT HUP INT TERM

# External, database-only, Redis-only and fully bundled modes must all parse.
docker compose config --quiet
docker compose --profile database config --quiet
docker compose --profile redis config --quiet
docker compose --profile database --profile redis config --quiet

docker compose --profile database --profile redis up -d --wait postgres redis
docker compose --profile database --profile redis run --rm app php artisan migrate --force
docker compose --profile database --profile redis up -d --wait app
test "$(curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1:18081/admin/login)" = 200
test "$(curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1:18081/ready)" = 200
