# AGENTS.md

## Scope
- This repository is a Symfony 7.4 backend. The application lives in `app/`.
- Use this file as the canonical agent policy for the whole repository.
- If you work inside `app/`, also read `app/AGENTS.md` for path-relative notes.

## Source Of Truth
- Trust the repository over assumptions. Check `app/composer.json`, config files, and CI before changing framework-specific behavior.
- Use Context7 through MCP whenever you need up-to-date Symfony, Doctrine, PHPUnit, Psalm, PHPCS, Composer, or Docker Compose documentation.
- If Context7 MCP is unavailable, say so explicitly and fall back to primary official documentation and the local codebase.

## Project Layout
- Symfony app root: `app/`
- Application code: `app/src`
- Tests: `app/tests`
- Configuration: `app/config`
- Migrations: `app/migrations`
- Assets: `app/assets`

## Runtime And Docker
- The default local runtime is the root `docker-compose.yaml`.
- Main services:
  - `php`: PHP 8.4 development container, app mounted at `/var/www/html`
  - `nginx`: serves `app/public`
  - `mysql`: MySQL 8.0 for local and test environments
  - `phpmyadmin`: optional database UI
- `app/compose.yaml` and `app/compose.override.yaml` exist, but prefer the root compose stack unless the task explicitly targets the nested compose files.
- Nginx config lives in `etc/docker/nginx/nginx.conf`.

## Symfony And PHP Best Practices
- Always add `declare(strict_types=1);` after the file header.
- Follow PSR-1, PSR-12, the local `.editorconfig`, and `app/phpcs.xml.dist`.
- Keep controllers thin. Put business logic in services, persistence logic in repositories, and validation in DTOs or dedicated validators.
- Prefer `final` classes and `readonly` dependencies where appropriate.
- Use constructor dependency injection. Do not use the container as a service locator.
- Keep methods, properties, and return types explicit. Avoid `mixed` unless the existing code already requires it.
- Keep existing PHPDoc blocks when the codebase uses them. Align tags and keep a blank line before `@return`.
- Follow the existing comparison style, including Yoda comparisons for literals and `null`.
- Use Symfony attributes and keep their ordering consistent with neighboring code.
- Preserve the existing language of comments and messages. Do not translate German comments to English or the reverse unless requested.

## API, Validation, And Security
- Never bind request payloads directly to Doctrine entities.
- Validate input with DTOs and Symfony Validator attributes.
- Keep response contracts stable. If you change output or error formats, update the corresponding serializer usage and Nelmio API documentation.
- Map domain and validation failures to the correct HTTP status codes. Do not expose stack traces or internal exception messages to clients.
- Treat all external input as untrusted. Enforce types, lengths, and allowlists where possible.
- Enforce authorization explicitly with security attributes, voters, or service-level guards.
- Never log secrets, tokens, passwords, or unsanitized user-controlled multiline input.

## Doctrine Rules
- Use Doctrine ORM attributes and typed collections.
- Do not place non-trivial business logic in entities.
- Do not concatenate SQL with user input. Bind parameters.
- Watch for N+1 queries and accidental lazy loading on hot paths.
- Every schema change must include a migration in `app/migrations`.
- Keep MySQL compatibility in mind for this project. Document and test any vendor-specific behavior.

## Testing Expectations
- Add or update tests for every behavior change.
- Prefer unit tests for pure logic, integration tests for services and repositories, and functional tests for HTTP endpoints.
- Keep tests deterministic. Do not rely on external network access or uncontrolled time.
- Respect the existing PHPUnit and DAMA Doctrine Test Bundle setup.

## Mandatory Verification After Every Change
- Run verification from the repository root and keep it Docker-based.
- Reproduce the CI test stage locally instead of inventing a lighter custom flow.
- Minimum required checks after each code change:
  - Psalm
  - PHP_CodeSniffer
  - CI-equivalent Symfony and PHPUnit test flow
- If the containers are not running yet, start them first:
  - `docker compose up -d php mysql`

## Required Docker Commands
- Prepare build directory:
  - `docker compose exec -T php bash -lc 'cd /var/www/html && mkdir -p build'`
- Code style:
  - `docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpcs'`
- Static analysis:
  - `docker compose exec -T php bash -lc 'cd /var/www/html && php vendor/bin/psalm --show-info=false --report=build/psalm-quality-report.json'`
- CI-equivalent test flow:
  - `docker compose exec -T php bash -lc 'cd /var/www/html && php bin/console lint:yaml -v --ansi --env=test config'`
  - `docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console cache:clear --env=test'`
  - `docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists'`
  - `docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction'`
  - `docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpunit --coverage-text --exclude-group ignore --coverage-clover build/phpunit.coverage.xml --coverage-cobertura build/phpunit.coverage.cobertura.xml --log-junit build/phpunit.xml'`

## CI Parity Notes
- These commands mirror the current `.gitlab-ci.yml` `test:codesniffer`, `test:static-analysis`, and `test:php` jobs.
- If you change dependencies or Composer metadata, also run the relevant build-stage checks inside Docker, especially `composer install` and `composer audit`.
- Do not claim success if any required Docker verification step was skipped. State exactly what ran and what failed or could not be executed.

## Reporting Rules
- In the final handoff, report:
  - what changed
  - why it changed
  - the exact Docker commands you ran
  - whether each command passed or failed
  - any remaining risks or follow-up work

## Forbidden Shortcuts
- Do not weaken Psalm, PHPCS, PHPUnit, Symfony, or Doctrine checks to get a green result.
- Do not skip migrations when schema changes are involved.
- Do not bypass Docker verification with host-only commands unless the user explicitly asks for that and accepts the deviation from CI.
