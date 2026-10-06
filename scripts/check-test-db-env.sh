#!/usr/bin/env sh
# Fails when app/.env.test cannot log in to the MySQL service that the dev
# docker-compose.yaml starts with its default root password.
# Usage: scripts/check-test-db-env.sh [compose-file] [env-test-file]
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="${1:-${ROOT_DIR}/docker-compose.yaml}"
ENV_TEST_FILE="${2:-${ROOT_DIR}/app/.env.test}"

# tr drops CR so a CRLF checkout parses the same as LF.
compose_password="$(tr -d '\r' < "${COMPOSE_FILE}" | sed -n 's/^[[:space:]]*MYSQL_ROOT_PASSWORD:[[:space:]]*\${MYSQL_ROOT_PASSWORD:-\([^}]*\)}.*$/\1/p')"
database_url="$(tr -d '\r' < "${ENV_TEST_FILE}" | sed -n 's/^DATABASE_URL="\{0,1\}\([^"]*\)"\{0,1\}[[:space:]]*$/\1/p')"

if [ -z "${compose_password}" ]; then
    echo "No default MYSQL_ROOT_PASSWORD found in ${COMPOSE_FILE}."
    exit 1
fi
if [ -z "${database_url}" ]; then
    echo "No DATABASE_URL found in ${ENV_TEST_FILE}."
    exit 1
fi

credentials="$(printf '%s' "${database_url}" | sed -n 's#^mysql://\([^@]*\)@mysql:3306/.*$#\1#p')"
if [ -z "${credentials}" ]; then
    echo "DATABASE_URL in ${ENV_TEST_FILE} does not point to mysql://<user>:<password>@mysql:3306/."
    exit 1
fi

if [ "root:${compose_password}" != "${credentials}" ]; then
    echo "DATABASE_URL in ${ENV_TEST_FILE} does not use root and the default MYSQL_ROOT_PASSWORD from ${COMPOSE_FILE}."
    exit 1
fi

echo "app/.env.test matches the MySQL root password in docker-compose.yaml."
