# Deployment (Backend) - Voraussetzungen und Ablauf

## Ziel
Diese Anleitung bereitet das Symfony-Backend fuer ein sicheres Deployment vor und stellt sicher, dass keine Secrets in GitHub landen.

## Was muss fuer ein Deployment vorhanden sein
1. Laufzeitumgebung:
   - PHP 8.4 mit benoetigten Extensions (`pdo`, `pdo_mysql`, `intl`, `opcache`).
   - Webserver (Nginx) mit Document Root `app/public`.
   - Datenbank (MySQL 8.x oder kompatibel), erreichbar ueber `DATABASE_URL`.
2. Build-/Release-Tools:
   - Composer 2.x
   - Zugriff auf Deploy-Host (SSH oder CI/CD Runner)
3. Umgebungsvariablen fuer `prod`:
   - `APP_ENV=prod`
   - `APP_DEBUG=0`
   - `APP_SECRET` (stark, mindestens 32 Zeichen)
   - `DATABASE_URL`
   - `CORS_ALLOW_ORIGIN`
   - `FRONTEND_URL`
   - `API_KEY` (falls externe API genutzt wird)
4. Rechte/Ordner:
   - Schreibrechte fuer `app/var/` (Cache/Logs)
   - Deploy-User darf Migrations ausfuehren

## Secret-Schutz vor GitHub Push
1. Niemals echte Werte in committe Dateien schreiben:
   - `app/.env`
   - `app/.env.test`
   - `docker-compose.yaml`
   - `app/compose.yaml`
2. Echte Werte nur in nicht versionierten Dateien oder Secret Stores:
   - lokal: `app/.env.local`, `app/.env.prod.local`
   - CI/CD: GitHub Secrets / GitLab Variables
3. Vor jedem Push ausfuehren:
```bash
./scripts/check-secrets.sh
```

## Release-Schritte (empfohlen)
1. Abhaengigkeiten installieren:
```bash
cd app
composer install --no-dev --prefer-dist --optimize-autoloader
```
2. Qualitaetschecks vor Release:
```bash
php -d memory_limit=-1 vendor/bin/phpcs
php vendor/bin/psalm --show-info=false
php -d memory_limit=-1 vendor/bin/phpunit
```
3. Datenbankmigrationen:
```bash
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
```
4. Cache aufbauen:
```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```
5. Health-Check nach Deployment:
   - API-Endpunkte (z. B. Login/Status) testen
   - Logs in `app/var/log/` pruefen

## Bereits im Repository vorbereitet
1. `app/.env` nutzt jetzt sichere Defaultwerte ohne MySQL-Credentials.
2. `docker-compose.yaml` und `app/compose.yaml` nutzen Platzhalter-/ENV-Defaults statt statischer Passwoerter.
3. `app/.env.prod.example` enthaelt die benoetigten Produktionsvariablen.
4. `scripts/check-secrets.sh` erkennt typische Secret-Muster vor dem Push.
