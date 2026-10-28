#!/usr/bin/env sh
# Starts a built runtime image and fails unless it keeps the PHP CVE-2026-109618 and
# CVE-2026-109619 fixes and mitigations (#146): PHP 8.4.27 or later, upload progress off
# for FPM, and nginx answering multipart requests with 415 before PHP parses them.
# It also pins the hardening of #56: no root process, a production php.ini with the
# project override on top, no pdo_pgsql, a warm prod cache, no X-Powered-By header, a
# HEALTHCHECK on /api/ready, and /api/ready answering 503 without a database.
# Usage: scripts/check-runtime-image.sh <image>
#   docker build -f app/Dockerfile --target runtime -t darts-backend .
#   sh scripts/check-runtime-image.sh darts-backend
set -eu

IMAGE="${1:?Usage: check-runtime-image.sh <image>}"
MIN_PHP_VERSION="8.4.27"

# The app needs this environment to answer HTTP at all. DATABASE_URL points at a closed
# port on purpose: /api/health must stay 200 and /api/ready must answer 503.
container="$(docker run -d --rm -p 127.0.0.1::10000 \
    -e APP_SECRET=check-runtime-image-secret \
    -e DATABASE_URL='mysql://check:check@127.0.0.1:1/check?serverVersion=8.4&charset=utf8mb4' \
    -e CORS_ALLOW_ORIGIN='^https?://localhost$' \
    -e LOCK_DSN=flock \
    -e MAILER_DSN=null://null \
    -e MESSENGER_TRANSPORT_DSN=sync:// \
    -e FRONTEND_URL=http://localhost \
    "${IMAGE}")"
ready_file="$(mktemp)"
trap 'docker stop "${container}" > /dev/null; rm -f "${ready_file}"' EXIT
port="$(docker port "${container}" 10000/tcp | sed -n 's/^127\.0\.0\.1:\([0-9]*\)$/\1/p' | head -n 1)"
base_url="http://127.0.0.1:${port}"

failed=0
fail() {
    echo "FAIL: $*"
    failed=1
}

php_version="$(docker exec "${container}" php -r 'echo PHP_VERSION;')"
if docker exec "${container}" php -r "exit(version_compare(PHP_VERSION, '${MIN_PHP_VERSION}', '>=') ? 0 : 1);"; then
    echo "ok: PHP ${php_version}"
else
    fail "PHP ${php_version} is older than ${MIN_PHP_VERSION}."
fi

# php-fpm -i reads the FPM SAPI's ini files, which can differ from the CLI's.
upload_progress="$(docker exec "${container}" php-fpm -i | sed -n 's/^session\.upload_progress\.enabled => \([^ ]*\) => .*$/\1/p')"
if [ "${upload_progress}" = "Off" ]; then
    echo "ok: session.upload_progress.enabled is Off for FPM"
else
    fail "session.upload_progress.enabled is '${upload_progress}' for FPM, expected Off."
fi

# The ini files FPM and the CLI load: php.ini-production as base, the project override after it.
if docker exec "${container}" php --ini | grep -q '^Loaded Configuration File: */usr/local/etc/php/php.ini$'; then
    echo "ok: php.ini is loaded"
else
    fail "php --ini reports no loaded php.ini."
fi

# php-fpm -i prints "name => local value => master value".
fpm_info="$(docker exec "${container}" php-fpm -i)"
check_fpm_ini() {
    actual="$(printf '%s\n' "${fpm_info}" | sed -n "s/^$1 => \([^=]*[^ =]\|\) *=> .*\$/\1/p" | head -n 1)"
    if [ "${actual}" = "$2" ]; then
        echo "ok: $1 is '$2' for FPM"
    else
        fail "$1 is '${actual}' for FPM, expected '$2'."
    fi
}
check_fpm_ini html_errors Off
check_fpm_ini zend.assertions -1
check_fpm_ini session.use_strict_mode On
check_fpm_ini expose_php Off
check_fpm_ini display_errors Off
check_fpm_ini log_errors On
check_fpm_ini opcache.validate_timestamps Off
# php.ini-production sets 128M and 4096; the override wins (SSE needs no buffering, shown as 'no value').
check_fpm_ini memory_limit 64M
check_fpm_ini output_buffering 'no value'
# Production error_reporting: E_ALL & ~E_DEPRECATED (22527 on PHP 8.4).
check_fpm_ini error_reporting 22527

modules="$(docker exec "${container}" php -m)"
if printf '%s\n' "${modules}" | grep -qx 'pdo_mysql'; then
    echo "ok: pdo_mysql is installed"
else
    fail "pdo_mysql is missing."
fi
if printf '%s\n' "${modules}" | grep -qx 'pdo_pgsql'; then
    fail "pdo_pgsql is installed, the app uses MySQL only."
else
    echo "ok: pdo_pgsql is absent"
fi

healthcheck="$(docker inspect --format '{{json .Config.Healthcheck.Test}}' "${IMAGE}")"
# Match the whole URL up to its closing quote, so /api/readyz or /api/ready/extra fails.
case "${healthcheck}" in
    *':${PORT}/api/ready\"'*) echo "ok: HEALTHCHECK calls /api/ready" ;;
    *) fail "HEALTHCHECK is ${healthcheck}, expected it to call /api/ready." ;;
esac

# Checked before the first request, so the cache comes from the build.
if docker exec "${container}" sh -c 'ls /app/var/cache/prod/*KernelProdContainer.php > /dev/null 2>&1'; then
    echo "ok: var/cache/prod holds a compiled container"
else
    fail "var/cache/prod holds no compiled container before the first request."
fi

# nginx starts after supervisord, so wait until it answers.
tries=0
until curl -s -o /dev/null "${base_url}/"; do
    tries=$((tries + 1))
    if [ "${tries}" -ge 30 ]; then
        echo "FAIL: nginx did not answer on ${base_url} within 30 seconds."
        exit 1
    fi
    sleep 1
done

# Every process in the container. Runs once nginx answers: the nginx and FPM masters start
# a few seconds after supervisord, and a root master must not slip through.
if processes="$(docker exec "${container}" ps -o user=,args=)"; then
    root_processes="$(printf '%s\n' "${processes}" | awk '$1 == "root" || $1 == "0"')"
    if ! printf '%s\n' "${processes}" | grep -q 'php-fpm: master' || ! printf '%s\n' "${processes}" | grep -q 'nginx: master'; then
        fail "ps did not list both the php-fpm and the nginx master: ${processes}"
    elif [ -z "${root_processes}" ]; then
        echo "ok: no process runs as root"
    else
        fail "root processes: ${root_processes}"
    fi
else
    fail "ps failed in the container."
fi

status_for() {
    curl -s -o /dev/null -w '%{http_code}' -X POST -H "Content-Type: $1" --data 'a=b' "${base_url}/api/login" || true
}

for content_type in 'multipart/form-data; boundary=x' 'Multipart/Mixed; boundary=x'; do
    status="$(status_for "${content_type}")"
    if [ "${status}" = "415" ]; then
        echo "ok: ${content_type} gets 415"
    else
        fail "${content_type} got ${status}, expected 415."
    fi
done

# Without a database PHP answers these with an error, but nginx must let them through.
for content_type in 'application/x-www-form-urlencoded' 'application/json'; do
    status="$(status_for "${content_type}")"
    if [ "${status}" != "415" ] && [ "${status}" != "000" ]; then
        echo "ok: ${content_type} reaches PHP (${status})"
    else
        fail "${content_type} got ${status}, expected it to reach PHP."
    fi
done

headers="$(curl -s -D - -o /dev/null "${base_url}/api/health" || true)"
if printf '%s' "${headers}" | head -n 1 | grep -q ' 200'; then
    echo "ok: /api/health answers 200 without a database"
else
    fail "/api/health did not answer 200: $(printf '%s' "${headers}" | head -n 1)"
fi
if printf '%s' "${headers}" | grep -qi '^x-powered-by:'; then
    fail "the response carries an X-Powered-By header."
else
    echo "ok: no X-Powered-By header"
fi

ready_status="$(curl -s -o "${ready_file}" -w '%{http_code}' "${base_url}/api/ready" || true)"
ready_body="$(cat "${ready_file}")"
if [ "${ready_status}" = "503" ] && [ "${ready_body}" = '{"success":false,"error":"DATABASE_UNAVAILABLE","message":"The database is unavailable."}' ]; then
    echo "ok: /api/ready answers 503 without a database"
else
    fail "/api/ready got ${ready_status} ${ready_body}, expected 503 with the DATABASE_UNAVAILABLE body."
fi

exit "${failed}"
