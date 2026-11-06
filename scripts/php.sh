#!/usr/bin/env bash
# Runs a command in app/ inside a PHP container that serves this checkout.
#
# Main checkout: the `darts` stack's php container, which mounts this app/.
# Worktree: a one-off container of the same image on the stack's network that mounts the
# worktree's app/. Its test database gets the suffix `_wt<issue>` through TEST_TOKEN, so
# the main checkout's test database keeps its own schema.
#
# Usage: scripts/php.sh php vendor/bin/phpunit --filter <TestClass>
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ "$(git rev-parse --git-dir)" = "$(git rev-parse --git-common-dir)" ]; then
  docker compose up -d --wait php mysql >/dev/null
  exec docker compose exec -T -w /var/www/html php "$@"
fi

mysql=$(docker compose ps -q mysql)
[ -n "$mysql" ] && [ "$(docker inspect -f '{{.State.Running}}' "$mysql" 2>/dev/null)" = true ] || {
  echo "Compose mysql is not running: run docker compose up -d in the workspace folder" >&2
  exit 1
}
project=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$mysql")
network=$(docker network ls -q --filter "label=com.docker.compose.project=$project" --filter 'label=com.docker.compose.network=default')
[ -n "$network" ] || { echo "Compose network for $project not found" >&2; exit 1; }
image=$(docker compose config --images php)
[[ $(basename "$PWD") =~ -([0-9]+)$ ]] || {
  echo "worktree folder must end in -<issue>, as scripts/worktree.sh new names it" >&2
  exit 1
}
token=_wt${BASH_REMATCH[1]}
mkdir -p "$HOME/.cache/composer"
exec docker run --rm -i --network "$network" -u "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer -e TEST_TOKEN="$token" \
  -v "$HOME/.cache/composer:/tmp/composer/cache" \
  -v "$PWD/app:/var/www/html" -v "$PWD/docs/contract:/var/www/docs/contract:ro" \
  -w /var/www/html --entrypoint "" "$image" "$@"
