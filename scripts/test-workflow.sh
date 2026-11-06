#!/usr/bin/env bash
# Exercise checkout selection and verification failure paths without starting Docker.
set -euo pipefail
unset $(git rev-parse --local-env-vars)
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
fail() { echo "test-workflow: $*" >&2; exit 1; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
mkdir -p "$tmp/bin" "$tmp/main/scripts" "$tmp/main/app"
export WORKFLOW_LOG="$tmp/commands" COMPOSE_PROJECT_NAME=custom
cat > "$tmp/bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$WORKFLOW_LOG"
case "$*" in
  "compose ps -q mysql") echo custom-mysql-id ;;
  "inspect -f {{.State.Running}} custom-mysql-id") echo "${MYSQL_RUNNING:-true}" ;;
  *com.docker.compose.project*custom-mysql-id) echo custom ;;
  "compose config --images php") echo php:test ;;
  "network ls "*) echo custom-network-id ;;
  "build "*) exit "${FAIL_BUILD:-0}" ;;
  "inspect "*) exit 1 ;;
esac
EOF
chmod +x "$tmp/bin/docker"
export PATH="$tmp/bin:$PATH"
cp "$root/scripts/php.sh" "$root/scripts/verify.sh" "$tmp/main/scripts/"
git -C "$tmp/main" init -q -b main
git -C "$tmp/main" add -A
git -C "$tmp/main" commit -qm init
: > "$WORKFLOW_LOG"
(cd "$tmp/main" && bash scripts/php.sh php example.php)
grep -q '^compose exec -T -w /var/www/html php php example.php$' "$WORKFLOW_LOG" || fail "main checkout did not use compose exec"
git -C "$tmp/main" worktree add -q --detach "$tmp/backend-291"
: > "$WORKFLOW_LOG"
(cd "$tmp/backend-291" && bash scripts/php.sh php example.php)
grep -q -- '--network custom-network-id' "$WORKFLOW_LOG" || fail "worktree used another project network"
grep -q -- 'TEST_TOKEN=_wt291' "$WORKFLOW_LOG" || fail "worktree did not isolate its test database"
grep -q -- "$tmp/backend-291/app:/var/www/html" "$WORKFLOW_LOG" || fail "worktree mounted another checkout"
: > "$WORKFLOW_LOG"
if (cd "$tmp/backend-291" && MYSQL_RUNNING=false bash scripts/php.sh php example.php) >/dev/null 2>&1; then fail "stopped MySQL was accepted"; fi
! grep -q '^run ' "$WORKFLOW_LOG" || fail "PHP started without MySQL"

# Replace the host checks and PHP runner with command stubs in the temporary repository.
for script in check-test-db-env.sh check-image-eol.sh check-gitlab-dockerfiles.sh test-ci-checks.sh check-secrets.sh test-worktree.sh test-workflow.sh; do
  cat > "$tmp/main/scripts/$script" <<'EOF'
#!/bin/sh
echo "host $(basename "$0")" >> "$WORKFLOW_LOG"
[ "${FAIL_HOST:-}" != "$(basename "$0")" ]
EOF
done
cat > "$tmp/main/scripts/php.sh" <<'EOF'
#!/bin/sh
echo "php $*" >> "$WORKFLOW_LOG"
[ "${FAIL_PHP:-}" != "$*" ]
EOF
cat > "$tmp/main/scripts/check-runtime-image.sh" <<'EOF'
#!/bin/sh
echo "runtime $*" >> "$WORKFLOW_LOG"
exit "${FAIL_RUNTIME:-0}"
EOF
: > "$WORKFLOW_LOG"
(cd "$tmp/main" && bash scripts/verify.sh) >/dev/null
grep -q 'vendor/bin/phpunit' "$WORKFLOW_LOG" || fail "verification skipped PHPUnit"
grep -q '^runtime ' "$WORKFLOW_LOG" || fail "verification skipped the runtime image"
for kind in host php runtime; do
  : > "$WORKFLOW_LOG"
  case $kind in
    host) export FAIL_HOST=check-image-eol.sh ;;
    php) export FAIL_PHP='composer audit' ;;
    runtime) export FAIL_RUNTIME=7 ;;
  esac
  if (cd "$tmp/main" && bash scripts/verify.sh) >/dev/null 2>&1; then fail "verification accepted a $kind failure"; fi
  case $kind in
    host) ! grep -q '^php ' "$WORKFLOW_LOG" || fail "verification continued after a host failure" ;;
    php) ! grep -q 'vendor/bin/phpcs' "$WORKFLOW_LOG" || fail "verification continued after a PHP failure" ;;
    runtime) grep -q '^image rm ' "$WORKFLOW_LOG" || fail "verification left its runtime image" ;;
  esac
  unset FAIL_HOST FAIL_PHP FAIL_RUNTIME
done
echo "workflow tests passed"
