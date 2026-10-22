#!/usr/bin/env sh
# Starts a built runtime image and fails unless it keeps the PHP CVE-2026-109618 and
# CVE-2026-109619 fixes and mitigations (#146): PHP 8.4.27 or later, upload progress off
# for FPM, and nginx answering multipart requests with 415 before PHP parses them.
# Usage: scripts/check-runtime-image.sh <image>
#   docker build -f app/Dockerfile --target runtime -t darts-backend .
#   sh scripts/check-runtime-image.sh darts-backend
set -eu

IMAGE="${1:?Usage: check-runtime-image.sh <image>}"
MIN_PHP_VERSION="8.4.27"

container="$(docker run -d --rm -p 127.0.0.1::10000 "${IMAGE}")"
trap 'docker stop "${container}" > /dev/null' EXIT
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

status_for() {
    curl -s -o /dev/null -w '%{http_code}' -X POST -H "Content-Type: $1" --data 'a=b' "${base_url}/api/login"
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

exit "${failed}"
