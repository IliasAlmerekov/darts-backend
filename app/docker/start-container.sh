#!/bin/sh
# Default command of the runtime image: render the nginx site for $PORT, then run
# nginx and php-fpm under supervisord. Console commands do not need this script:
# secrets are read from files by the Symfony configuration itself.

set -eu

PORT="${PORT:-10000}"
case "$PORT" in
    '' | *[!0-9]*)
        echo "PORT must be a number, got '$PORT'." >&2
        exit 1
        ;;
esac

mkdir -p /tmp/nginx
sed "s/__PORT__/${PORT}/g" /etc/nginx/templates/default.conf.template > /tmp/nginx/default.conf

exec /usr/bin/supervisord -c /etc/supervisord.conf
