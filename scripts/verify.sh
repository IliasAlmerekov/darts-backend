#!/usr/bin/env bash
# Runs the checks of .github/workflows/ci.yml locally and stops at the first failure.
# Works from the main checkout and from a linked worktree: scripts/php.sh picks the PHP
# container that serves this checkout. Needs the `darts` Compose stack.
# The runtime image is built and checked only when the branch changes app/Dockerfile or
# app/docker/.
#
# Usage: scripts/verify.sh
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
step() { printf '\n== %s\n' "$*"; }

step host checks
sh scripts/check-test-db-env.sh
sh scripts/check-image-eol.sh
sh scripts/check-gitlab-dockerfiles.sh
sh scripts/test-ci-checks.sh
bash scripts/check-secrets.sh

x() { bash scripts/php.sh "$@"; }

step composer
x composer install --no-interaction --no-progress
x composer audit
x mkdir -p build

step phpcs
x php -d memory_limit=-1 vendor/bin/phpcs

# CI starts cold. A warm Psalm cache keeps cross-file findings such as
# PossiblyUnusedProperty stale, so the local run passes where CI fails.
step psalm
x php bin/console cache:warmup --env=dev
x php vendor/bin/psalm --no-cache --show-info=false --report=build/psalm-quality-report.json

step symfony and doctrine
x php bin/console lint:yaml -v --ansi --env=test config
x php -d memory_limit=-1 bin/console lint:container --env=test
x php -d memory_limit=-1 bin/console cache:clear --env=test
x php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists
x php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction
x php -d memory_limit=-1 bin/console doctrine:schema:validate --env=test

step phpunit
x php -d memory_limit=-1 vendor/bin/phpunit

remote=$(git remote -v | awk '/github\.com/ && /\(push\)/ { print $1; exit }')
base=$(git merge-base HEAD "$remote/main")
if git diff --name-only "$base" -- app/Dockerfile app/docker | grep -q .; then
  step runtime image
  docker build --pull -f app/Dockerfile --target runtime -t darts-backend:verify .
  sh scripts/check-runtime-image.sh darts-backend:verify
fi

step all checks passed
