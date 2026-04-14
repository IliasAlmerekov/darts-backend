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

## App-Specific Conventions
- In `src/`, preserve the proprietary file header when surrounding files use it, then place `declare(strict_types=1);` immediately after the header.
- Use `#[\Override]` on overriding methods where neighboring code already follows that convention.
- Prefer `#[MapRequestPayload]` and `#[MapQueryParameter]` for controller input binding instead of hand-rolled request parsing.
- Keep controller logic thin and move business behavior into services.
- Treat `FormErrorIterator` as directly iterable; avoid unnecessary `getIterator()` calls.
- When reading optional methods from `UserInterface`, guard them with `method_exists(...)` unless the code already depends on the concrete `App\Entity\User`.
- Build frontend URLs from configuration such as `FRONTEND_URL`; do not hard-code hosts.
- Preserve local comparison style, including Yoda conditions where the neighboring code uses them.

## Docker Reminder
- Even if your current working directory is `app/`, verification still follows the root repository policy:
  - run commands from the repository root
  - use `rtk docker compose ...`
  - do not run host-local `php`, `composer`, `phpcs`, `psalm`, or `phpunit` directly
