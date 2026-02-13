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

## Docker

The root `docker-compose.yaml` provides PHP, Nginx, and MySQL for local development.

## Deployment

For production prerequisites and rollout steps see `DEPLOYMENT_DE.md`.
Before pushing to a public repository run:

```bash
./scripts/check-secrets.sh
```
