# AGENTS.md

## App Scope
- This directory is the Symfony application root.
- Follow the canonical repository policy in `/home/ilias.almerekov/Projects/Backend/backend/AGENTS.md`.

## Path Mapping
- `src/` maps to application code.
- `tests/` maps to PHPUnit tests.
- `config/` maps to Symfony configuration.
- `migrations/` maps to Doctrine migrations.
- `assets/` maps to AssetMapper and Stimulus assets.

## Relative Verification Commands
- If your current working directory is `app/`, run the same CI-equivalent checks with relative paths:
  - `mkdir -p build`
  - `php -d memory_limit=-1 vendor/bin/phpcs`
  - `php vendor/bin/psalm --show-info=false --report=build/psalm-quality-report.json`
  - `php bin/console lint:yaml -v --ansi --env=test config`
  - `php -d memory_limit=-1 bin/console cache:clear --env=test`
  - `php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists`
  - `php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction`
  - `php -d memory_limit=-1 vendor/bin/phpunit --coverage-text --exclude-group ignore --coverage-clover build/phpunit.coverage.xml --coverage-cobertura build/phpunit.coverage.cobertura.xml --log-junit build/phpunit.xml`

## Docker Reminder
- Prefer running those commands through the root `docker compose` `php` service so the environment matches CI as closely as possible.
