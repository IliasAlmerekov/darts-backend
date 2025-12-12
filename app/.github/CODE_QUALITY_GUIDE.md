# Code Quality Guide (PHP 8.2, Symfony)

Keep changes passing `phpcs`, `psalm`, and `phpunit`. Follow these rules when adding or modifying code:

- **Namespaces & Folders**: Match file path (e.g. services under `App\Service\Game\*`, `App\Service\Player\*`, `App\Service\Invitation\*`, etc.). Update imports after moves.
- **Docblocks**: Add `@param`/`@return` for public methods and constructors when required by phpcs. Ensure names match the signature and order parameters without defaults before ones with defaults.
- **Override attribute**: Add `#[Override]` on methods implementing interfaces.
- **License header**: Every PHP file starts with the project license docblock immediately after `<?php` (no blank line) and before `declare()`/namespace.
- **Final classes in tests**: Do not mock final classes/DTOs; mock interfaces instead. If a class is final and no interface exists, use real instances.
- **UserInterface safety**: When reading user id/roles on `UserInterface`, guard with `method_exists` or concrete `User` type checks.
- **Form errors**: Treat `FormErrorIterator` as iterable directly (`foreach`), avoid calling `getIterator()`.
- **Routing/redirects**: Build frontend links from `FRONTEND_URL` env/parameter; avoid hard-coded hosts.
- **DTO mapping**: Use `MapRequestPayload`/`MapQueryParameter` and keep controller logic thin; business logic stays in services.
- **Throw/undo logic**: Apply multipliers for double/triple, track busts correctly, and restore scores on undo using the same multiplier.
- **Collections/order**: When deriving active player or positions, fall back to first eligible player to avoid nulls in active games.
- **Suppressions**: Only add Psalm suppressions with a short reason when necessary.
- **String concatenation**: No spaces around `.` (e.g. `$base.'/path'`).
- **Braces**: Multi-line constructors/methods/classes put opening brace on a new line and closing brace on its own line (PSR-12/PHPCS).
- **Method signatures**: Keep all parameters on the same line as the method/function name; avoid breaking after the opening parenthesis.
- **Comparisons**: Use Yoda conditions (`0 === $score`, `null === $value`) to avoid accidental assignments.
- **Public APIs**: Public methods in services/controllers should include docblocks with accurate `@param`/`@return`.

Run locally:

- `php -d memory_limit=-1 vendor/bin/phpcs`
- `php -d memory_limit=-1 vendor/bin/psalm --show-info=false`
- `php -d memory_limit=-1 vendor/bin/phpunit`
