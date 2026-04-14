# AGENTS.md

## Scope

- This repository is a Symfony 8.0 backend. The application lives in `app/`.
- Use this file as the canonical repository policy.
- If you work inside `app/`, also read `app/AGENTS.md`.

## Source Of Truth

Before non-trivial work, read:

1. `AGENTS.md`
2. `app/AGENTS.md`
3. `.codex/AGENTS.md`
4. `.codex/context/current-focus.md`
5. `.codex/context/project-map.md`
6. `.codex/context/domain-map.md`
7. `.codex/context/decisions.md`
8. `.codex/context/engineering-principles.md`

Trust the repository over stale prose when they disagree.

Use `engineering-principles.md` as the project-specific decision framework for
abstraction, pattern fit, and wrong-layer fixes. Do not apply SOLID, DRY, KISS,
or YAGNI mechanically.

## Local Workflow

- Non-trivial work should use the local workflow:
  - `brainstorming-feature`
  - `planning-feature`
  - `subagent-development`
- `lead_orchestrator` owns execution routing and completion criteria.
- Work in the current branch.
- Do not require, suggest, or create `git worktree`.

## Execution Policy

- All shell commands must go through `rtk`.
- Never execute raw shell commands.

## Current Stack

- PHP: `>=8.4`
- Symfony: `8.0.*`
- Doctrine ORM: `^3.5.8`
- Doctrine DBAL: `^4.0`
- PHPUnit: `^12.5.8`
- Psalm: `^6.13.1`
- PHPCS: via `escapestudios/symfony2-coding-standard`
- API docs: `nelmio/api-doc-bundle`
- Local runtime: root `docker-compose.yaml`

## Runtime And Docker

- Default runtime is the root `docker-compose.yaml`.
- Main services:
  - `php`
  - `nginx`
  - `mysql`
  - `phpmyadmin`
- App path inside the PHP container: `/var/www/html`
- Prefer the root compose stack over nested compose files unless the task explicitly targets them.

## Symfony And PHP Rules

- Always add `declare(strict_types=1);` after the file header.
- Follow PSR-1, PSR-12, `.editorconfig`, and `app/phpcs.xml.dist`.
- Keep controllers thin.
- Put business logic in services.
- Put persistence logic in repositories.
- Prefer `final` classes and explicit types.
- Use constructor dependency injection.
- Do not use the container as a service locator.
- Preserve existing code style, PHPDoc patterns, and comparison style from nearby code.
- Use `#[\Override]` on overriding methods where the surrounding code follows that convention.
- Prefer Symfony request mapping attributes such as `#[MapRequestPayload]` and `#[MapQueryParameter]` for controller DTO and query binding.
- Preserve existing proprietary license headers and add them in new application PHP files when the surrounding files in that area use the same header.
- Keep Yoda comparisons where the existing code around the change uses them.

## API, Validation, And Security

- Never bind request payloads directly to Doctrine entities.
- Use DTOs and Symfony Validator attributes for input validation.
- Treat API output as a contract.
- If response shape changes, review serializer usage and Nelmio implications.
- Map failures to correct HTTP status codes.
- Do not expose internal exception details to clients.
- Treat all external input as untrusted.
- Enforce authorization explicitly.
- Never log secrets, tokens, passwords, or unsafe raw user input.
- When working with `UserInterface`, guard optional methods with `method_exists(...)` unless the code already depends on a concrete application user type.
- Build frontend-facing URLs and redirects from configuration parameters such as `FRONTEND_URL` rather than hard-coded hosts.

## Doctrine Rules

- Use Doctrine ORM attributes and typed collections.
- Do not put non-trivial business logic in entities.
- Do not concatenate SQL with user input.
- Bind parameters.
- Watch for N+1 queries, accidental lazy loading, and oversized transaction scopes.
- Every schema change must include a migration in `app/migrations`.

## Testing Expectations

- Add or update tests for every behavior change.
- Prefer unit tests for pure logic.
- Prefer integration tests for services and repositories.
- Prefer functional tests for HTTP endpoints.
- Keep tests deterministic.
- Respect the existing PHPUnit and DAMA Doctrine Test Bundle setup.

## Mandatory Verification After Code Changes

- Run verification from the repository root.
- Keep verification Docker-based to match CI.
- Minimum required checks after code changes:
  - PHPCS
  - Psalm
  - CI-equivalent Symfony and PHPUnit flow

If the containers are not running yet, start them first:

```bash
rtk docker compose up -d php mysql
```

Required command forms:

```bash
rtk docker compose exec -T php bash -lc 'cd /var/www/html && mkdir -p build'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpcs'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php vendor/bin/psalm --show-info=false --report=build/psalm-quality-report.json'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php bin/console lint:yaml -v --ansi --env=test config'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console cache:clear --env=test'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpunit --coverage-text --exclude-group ignore --coverage-clover build/phpunit.coverage.xml --coverage-cobertura build/phpunit.coverage.cobertura.xml --log-junit build/phpunit.xml'
```

## CI Parity Notes

- These commands should mirror the current `.gitlab-ci.yml` test stages.
- If Composer dependencies or metadata change, also run the relevant dependency checks inside Docker.
- Do not claim success if required verification was skipped.

## Reporting Rules

In the final handoff, report:

- what changed
- why it changed
- exact Docker commands run
- pass or fail status for each command
- remaining risks or follow-up work

## Forbidden Shortcuts

- Do not weaken Psalm, PHPCS, PHPUnit, Symfony, or Doctrine checks to get a green result.
- Do not skip migrations when schema changes are involved.
- Do not bypass Docker verification with host-only commands unless the user explicitly accepts that deviation.
