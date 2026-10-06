# Darts App (backend)

Symfony 8 / PHP 8.4 API for the darts game: game lifecycle, throws, statistics,
invitations, registration, security. The application root is `app/`
(`src/`, `tests/`, `config/`, `migrations/`). The frontend lives in a separate
repository and consumes this API.

## Conventions

Match the neighbouring code; these are the conventions it follows:

- `declare(strict_types=1);` in every PHP file, right after the proprietary license
  header where the surrounding files carry one.
- PSR-12, `app/.editorconfig`, `app/phpcs.xml.dist`.
- Thin controllers, business logic in services, persistence in repositories.
- `final` classes, explicit types, constructor injection.
- `#[\Override]` on overriding methods, and Yoda comparisons, where nearby code uses them.
- Controller input binds through `#[MapRequestPayload]` / `#[MapQueryParameter]` into DTOs
  validated with Symfony Validator attributes, never into Doctrine entities.
- Optional `UserInterface` methods are guarded with `method_exists(...)` unless the code
  already depends on `App\Entity\User`.
- `FormErrorIterator` is iterable directly, without `getIterator()`.
- Frontend URLs and redirects come from configuration such as `FRONTEND_URL`.
- Errors map to the correct HTTP status; clients never see internal exception details.

## API contract

Response shape is a contract with the frontend. When a response changes, check serializer
groups and the Nelmio API docs in the same change.

## Doctrine

Every schema change ships with a migration in `app/migrations`. Bind query parameters.
Watch for N+1 queries and lazy loading in loops.

## Tests

Every behaviour change gets a test: unit for pure logic, integration for services and
repositories, functional for HTTP endpoints. The test DB uses DAMA Doctrine Test Bundle.

## Verification

Run checks in Docker from the repository root, to match `.gitlab-ci.yml`. Host-local
`php`, `composer`, or `vendor/bin/*` runs only when the user accepts the deviation.

```bash
docker compose up -d php mysql
docker compose exec -T php sh -lc 'cd /var/www/html && mkdir -p build'
docker compose exec -T php sh -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpcs'
docker compose exec -T php sh -lc 'cd /var/www/html && php vendor/bin/psalm --show-info=false --report=build/psalm-quality-report.json'
docker compose exec -T php sh -lc 'cd /var/www/html && php bin/console lint:yaml -v --ansi --env=test config'
docker compose exec -T php sh -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console cache:clear --env=test'
docker compose exec -T php sh -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists'
docker compose exec -T php sh -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction'
docker compose exec -T php sh -lc 'cd /var/www/html && XDEBUG_MODE=coverage php -d memory_limit=-1 vendor/bin/phpunit --coverage-text --coverage-clover build/phpunit.coverage.xml --coverage-cobertura build/phpunit.coverage.cobertura.xml --log-junit build/phpunit.xml'
```

A green result comes from fixing the code. Never change the Psalm, PHPCS, or PHPUnit
configs to hide a failure; a deliberate config change, such as excluding the slow
`benchmark` group from the default PHPUnit run, ships with its reason in the commit and a
test that pins it. In the final report, list each command run with its pass or fail status, and name
any check you skipped.

## Guardrails

Ask the user first before you run a migration against a non-test database, change
dependencies, or touch auth, roles, tokens, or credentials. Keep secrets,
tokens, and passwords out of logs, prompts, and commits. Commits carry the human author
only, with no `Co-Authored-By` trailer.
