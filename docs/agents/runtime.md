# Local runtime

Locally backend and frontend run as one Compose stack, the project `darts`: nginx on :8001,
php, mysql, and the frontend on :5173. Start it with `docker compose up -d` in the
workspace folder that holds both repos. The php container mounts the main checkout's `app/`.

When `check-test-db-env.sh` fails, put the `DATABASE_URL` with the root password from
`docker-compose.yaml` in `app/.env.test.local`. Raise `MIN_PHP_VERSION` in
`scripts/check-runtime-image.sh` with each PHP security release.

