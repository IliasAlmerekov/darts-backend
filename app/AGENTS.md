# AGENTS.md — Symfony 7.3 / PHP 8.2+ / Doctrine ORM 3.5 / PHPUnit 12 / Psalm / PHPCS (Escapestudios Symfony)

## 0) Quality bar (mandatory)
- Produce production-grade code: correctness, security, maintainability.
- Keep diffs small and reviewable.
- Do not change public API behavior unless explicitly requested.
- Add/extend tests for every behavior change.
- Before declaring done: run required checks and report exact commands + outcomes.

## 1) Stack constraints (source of truth: composer.json)
- PHP >= 8.2 (target 8.4). Always use `declare(strict_types=1);`.
- Symfony 7.3.* (FrameworkBundle, Runtime, Console, Dotenv, Serializer, Validator, Security, Form, etc.).
- Doctrine ORM ^3.5.8, DBAL ^3.10.4, Doctrine Migrations.
- NelmioApiDocBundle ^5.8, NelmioCorsBundle ^2.6.
- Frontend: AssetMapper + StimulusBundle (no bundlers).
- Tooling:
  - PHPUnit ^12.5
  - Psalm ^6.13.1 (+ psalm/plugin-symfony, baseline if present)
  - PHPCS with Escapestudios Symfony2 Coding Standard + Micheh GitLab report
  - Roave Security Advisories (dependency conflict guard)
  - CycloneDX SBOM plugin

## 2) Project structure & namespaces
- PSR-4:
  - `App\` -> `src/`
  - `App\Tests\` -> `tests/`
- Keep HTTP concerns in Controllers only; business logic in Services; DB queries in Repositories.
- Do not place heavy logic in Entities.
- Keep boundaries clear: Controller -> DTO/Validator -> Service -> Repository/EntityManager.

## 3) PHPCS (must match your ruleset)
Use the repository ruleset XML (coding standard) as the only source of truth.

### Important behavior of your PHPCS config
- Scans: `src/`
- Excludes: `*/tests/*`, `*/Kernel.php`
- Uses: `vendor/escapestudios/symfony2-coding-standard/Symfony`
- Has GitLab JSON report output:
  - `build/phpcs-quality-report.json`
- Disabled sniffs:
  - Interface name prefix rule
  - Abstract name prefix rule
  - One-line arguments rule

### Required command (style gate)
- `vendor/bin/phpcs -q --standard=phpcs.xml.dist`

If `build/` does not exist, create it before running PHPCS:
- `mkdir -p build`

Do not “fix” style by weakening the ruleset. Prefer `vendor/bin/phpcbf` only when it preserves intended behavior.

## 4) Definition of Done — required local checks
Run these checks (or closest equivalents present in repo) before "done":

### Composer/platform
- `composer validate --no-check-all --strict`
- `php -v`

### Static analysis
- `vendor/bin/psalm --no-progress`

### Code style (PHPCS)
- `mkdir -p build`
- `vendor/bin/phpcs -q --standard=phpcs.xml.dist`

### Tests
- `vendor/bin/phpunit`

### Dependency security
- `composer audit`
  - If unavailable in the environment, say so explicitly and propose CI verification.

### Symfony sanity (when relevant)
- `php bin/console lint:yaml config/`
- `php bin/console cache:clear`

Always include the exact commands run and their outcomes.

## 5) Testing rules (PHPUnit 12 + Symfony BrowserKit)
- Every behavior change must include tests:
  - Unit tests for pure logic.
  - Integration tests for services + Doctrine.
  - Functional tests for HTTP endpoints using `KernelBrowser`.
- Deterministic tests only:
  - No real network.
  - No reliance on system time without abstraction.
- For DB tests:
  - Use `dama/doctrine-test-bundle` (transaction rollback). Do not write manual cleanup unless necessary.

## 6) API rules (Serializer + Validator + Nelmio)
- Validate request payloads via DTOs (Symfony Validator attributes), not Entities.
- Use Serializer/DTO mapping for stable output (optionally groups).
- Error handling must be stable:
  - Validation errors: consistent JSON format.
  - Domain errors: map to correct HTTP codes (400/401/403/404/409/422/500).
- Never leak internal exception traces/messages to clients.
- If API output changes: update/extend Nelmio OpenAPI annotations/config accordingly.

## 7) Security rules (Symfony Security + CSRF)
- Treat all request data as untrusted:
  - enforce type constraints
  - enforce max lengths
  - allowlists when possible
- Authorization must be enforced (attributes/voters and/or service-layer guards).
- CSRF protection for browser form flows and other state-changing requests where applicable.
- Logging:
  - never log secrets/tokens/passwords
  - sanitize user-controlled strings (avoid newline/control-char injection)

## 8) Doctrine/DB rules
- No SQL concatenation with user input; always bind parameters.
- Avoid N+1 and accidental lazy-loading in hot paths.
- Schema changes require Doctrine migration in `migrations/`.
- Use transactions for atomic multi-step writes.
- PostgreSQL is primary; if MySQL differs, document and guard with tests/notes.

## 9) Frontend rules (AssetMapper + Stimulus)
- Do not introduce bundlers (Webpack/Vite/etc.).
- Stimulus controllers live in `assets/controllers`.
- For fetch/XHR state changes, include CSRF token where required.
- WCAG 2.2:
  - labels + aria-describedby for errors
  - keyboard accessibility + visible focus
  - no color-only state indication

## 10) Coding conventions (PHP 8.2+)
- Always include `declare(strict_types=1);`.
- Typed params/returns/properties everywhere; avoid `mixed` unless unavoidable.
- Prefer readonly and value objects where helpful.
- Prefer constructor DI; avoid service locator.
- Avoid broad `catch (\Throwable)` without explicit handling strategy.
- Prefer fixing Psalm issues over expanding baseline.
  - If baseline update is unavoidable: explain and keep it minimal.

## 11) Response format (how to report work)
Include:
- Summary of changes (bullets)
- Why (short)
- Verification commands + results (exact)
- Risks/edge cases
- Follow-ups (optional)

## 12) Disallowed shortcuts
- Do not skip/disable tests to make CI pass.
- Do not weaken validation/security rules to “green” a build.
- Do not silence errors with broad catches or ignored return values.
- Do not relax PHPCS/Psalm rulesets unless explicitly requested.
