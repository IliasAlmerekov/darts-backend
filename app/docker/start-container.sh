#!/bin/sh

set -eu

PORT="${PORT:-10000}"

sed "s/__PORT__/${PORT}/g" /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf

exec /usr/bin/supervisord -c /etc/supervisord.conf