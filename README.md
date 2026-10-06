# Darts Backend

## Local secrets setup

This project keeps secrets out of VCS. Use a local env file instead.

1. Copy the template:

```bash
cp app/.env.local.example app/.env.local
```

2. Fill in required values in `app/.env.local`:
- `APP_SECRET`
- `DATABASE_URL`
- `API_KEY`
- (optional) `CORS_ALLOW_ORIGIN`, `FRONTEND_URL`

Notes:
- Symfony loads `.env.local` for `dev` and `prod`, but **not** for `test`.
- If you need test secrets locally, create `app/.env.test.local` and set `APP_SECRET` / `DATABASE_URL` there.
- `app/.env.test` already matches the default MySQL root password in `docker-compose.yaml`. Override `DATABASE_URL` in `app/.env.test.local` only if you set `MYSQL_ROOT_PASSWORD`, or if your `mysql_data` volume was created before commit 7ee392b, when the root password was `root123`. `docker compose down -v` recreates the volume with the current default and deletes the local database.

## Docker

The root `docker-compose.yaml` provides PHP, Nginx, and MySQL for local development.

## Deployment

The production container is expected to run behind a real HTTP server (`nginx + php-fpm`), not PHP's built-in development server.

For production prerequisites and rollout steps see `DEPLOYMENT_DE.md`.
Before pushing to a public repository run:

```bash
./scripts/check-secrets.sh
```
