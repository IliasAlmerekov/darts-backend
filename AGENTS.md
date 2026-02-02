Always review `~/.codex/AGENTS.md` at the beginning of each new Codex CLI session.

# Project scope
- Symfony 7.3 backend lives in `app/`; code in `app/src`, tests in `app/tests`, config in `app/config`, migrations in `app/migrations`, assets in `app/assets`.

# Docker and runtime
- Root `docker-compose.yaml` defines PHP-FPM 8.4, Nginx, MySQL, phpMyAdmin, and shared sockets.
- `app/compose.yaml` + `app/compose.override.yaml` define the Symfony stack's Postgres and Mailpit.
- Nginx config at `etc/docker/nginx/nginx.conf` expects docroot `/var/www/html/public` and PHP-FPM socket `/var/run/php-fpm.socket`.
- Doctrine uses `DATABASE_URL` in `app/config/packages/doctrine.yaml`; be explicit about which database backend you target.
- When changing services, ports, env vars, or volumes, update the relevant compose file(s) and keep them consistent.

# Formatting
- Follow `app/.editorconfig`: 4-space indentation, LF, trim trailing whitespace, final newline.
- Compose YAML uses 2-space indentation.

# PHP standards
- Follow PSR-1 and PSR-12; autoloading is PSR-4 (`App\\` => `app/src`, `App\\Tests\\` => `app/tests`).
- Adhere to Symfony coding standard per `app/phpcs.xml.dist`.
- Always include `declare(strict_types=1);` after any file header block.
- Use typed properties and explicit parameter/return types; avoid `mixed` unless already used.
- Keep phpdoc `@param`/`@return` blocks even when types are declared; align and leave a blank line before `@return`.
- Prefer Yoda comparisons for literals and null checks (`null === $var`, `0 === $count`) as in existing code.
- Prefer `final`/`readonly` for services, controllers, and DTOs when appropriate; match existing patterns.
- Use Symfony/Nelmio attributes for routing and API docs; keep attribute ordering and formatting consistent.
- Preserve existing comment language (German/English); do not translate unless requested.
- Use trailing commas in multiline arrays and argument lists where it improves diff stability.

# Doctrine and migrations
- Entities use Doctrine ORM PHP attributes; keep collections typed (e.g., `Collection<int, Entity>`).
- New migrations go in `app/migrations` and follow `VersionYYYYMMDDHHMMSS.php` naming.

# Tooling
- Static analysis: Psalm (`app/psalm.xml` + baseline).
- Style checks: PHP_CodeSniffer (`app/phpcs.xml.dist`).
- Tests: PHPUnit (`app/phpunit.dist.xml`).
