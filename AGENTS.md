# Darts App (backend)

Symfony 8 / PHP 8.4 API for the darts game: game lifecycle, throws, statistics,
invitations, registration, security. The application root is `app/`
(`src/`, `tests/`, `config/`, `migrations/`). The frontend lives in a separate
repository and consumes this API.

## Domain

Before changing game, lobby, or login behaviour, read `GLOSSARY.md` and the ADRs in
`docs/adr/` that touch the area. They record rules the code enforces, such as one open
lobby and one started game. Game logic lives in `app/src/Service/Game/`, one service per
use case behind an interface.

## Scope

Solve the whole task with the least new code. Before the first edit, list every place the
change must reach: callers, tests, fixtures, config, serializer groups, API docs. That list
is the scope; features nobody asked for are outside it, named in one line in the report.

Reach for the first option that works: no code at all, then a helper or service already in
`app/src`, then Symfony or PHP built-ins, then an installed package, then new code.

- Add no abstraction, interface, option, or "for later" code the task does not need.
  Deletion beats addition.
- A bug fix greps every caller of the function it touches and fixes the root cause once,
  in the shared code.
- Moved or merged code keeps its validation and error handling.
- Between options of equal size, take the one correct on edge cases.
- A known shortcut gets a comment: `shortcut: <the limit>, <when to upgrade>`.
- Validation at trust boundaries, error handling that prevents data loss, and security
  are never cut.

The final report lists each verification command with its pass or fail status, names every
check you skipped, and ends with what you did not check and the risk left.

## Conventions

Match the neighbouring code. PHPCS and Psalm enforce style and types; these are the
conventions no tool checks:

- Every PHP file, migrations included, starts with the proprietary license header and
  `declare(strict_types=1);`.
- Controller input binds through `#[MapRequestPayload]` / `#[MapQueryParameter]` into DTOs
  in `app/src/Dto/`, validated with Symfony Validator attributes. Entities stay out of
  request binding.
- An error the client should see is an exception that extends
  `App\Exception\ApiHttpException` (or implements `ApiExceptionInterface` and Symfony's
  `HttpExceptionInterface`) with a code from `ErrorCode`. `App\Security\ErrorController`
  renders its code, message, and status, and hides other exceptions behind a generic body.
- Optional `UserInterface` methods are guarded with `method_exists(...)` unless the code
  already depends on `App\Entity\User`.
- `FormErrorIterator` is iterable directly, without `getIterator()`.
- Frontend URLs and redirects come from configuration such as `FRONTEND_URL`.

## API contract

Response shape is a contract with the frontend, which lives next to this repo in
`../dartsapp`. Before changing a route or a response, grep its path in `../dartsapp/src`
and list the consumers in the PR. Serializer groups and the Nelmio API docs change in the
same commit as the response. The frontend validates responses against a dump of those docs,
`../dartsapp/docs/backend-api-contract.json`, so a changed response also gets a frontend
issue to refresh that dump.

## Doctrine

Every schema change ships with a migration in `app/migrations`. Generate it against the
dev database, then add the license header and review the SQL:

```bash
docker compose exec -T -w /var/www/html php php bin/console doctrine:migrations:diff
```

Bind query parameters. Watch for N+1 queries and lazy loading in loops.

## Tests

Every behaviour change gets a test, however small; only changes that leave behaviour
untouched (a rename, docs, formatting) go without one. Unit tests cover pure logic,
integration tests cover services and repositories, and functional tests cover HTTP
endpoints (`app/tests/Controller/`). DAMA Doctrine Test
Bundle wraps the whole run in one transaction that rolls back. Helpers in
`app/tests/Support/`:

- `TestUserFactory::create()` persists a throwaway user for functional tests.
- `ScratchDatabase` gives a test its own MySQL database outside the DAMA transaction, for
  DDL or a second connection that must commit.

While you work, run only the tests you touch, from the repo root:

```bash
docker compose exec -T -w /var/www/html php php vendor/bin/phpunit --filter <TestClass>
```

Run the full verification below once, before the commit.

## Verification

The checks mirror `.github/workflows/ci.yml`, the gate on every PR; `.gitlab-ci.yml` runs
the same set. Run them in Docker from the repository root. Host-local `php`, `composer`,
or `vendor/bin/*` runs only when the user accepts the deviation.

Locally backend and frontend run as one Compose stack, the project `darts`: nginx on :8001,
php, mysql, and the frontend on :5173. Start it with `docker compose up -d` in the
workspace folder that holds both repos. The php container mounts this checkout's `app/`.

The two `scripts/` checks run on the host, because the container mounts only `app/`.
`check-test-db-env.sh` fails when `app/.env.test` and `docker-compose.yaml` disagree on the
MySQL root password; put the matching `DATABASE_URL` in `app/.env.test.local` to fix it.
`check-image-eol.sh` fails when a shell script, a Dockerfile, or a file under `app/docker/`
lacks `eol=lf` in `.gitattributes` or is committed with CRLF.

```bash
sh scripts/check-test-db-env.sh
sh scripts/check-image-eol.sh
docker compose up -d php mysql
x() { docker compose exec -T -w /var/www/html php "$@"; }
x composer install --no-interaction
x composer audit
x mkdir -p build
x php -d memory_limit=-1 vendor/bin/phpcs
x php vendor/bin/psalm --show-info=false --report=build/psalm-quality-report.json
x php bin/console lint:yaml -v --ansi --env=test config
x php -d memory_limit=-1 bin/console cache:clear --env=test
x php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists
x php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction
x php -d memory_limit=-1 bin/console doctrine:schema:validate --env=test
x php -d memory_limit=-1 vendor/bin/phpunit
```

A green result comes from fixing the code. The Psalm, PHPCS, and PHPUnit configs and
`app/psalm-baseline.xml` change only on purpose: the baseline only shrinks, and a
deliberate config change, such as excluding the slow `benchmark` group from the default
PHPUnit run, ships with its reason in the commit and a test that pins it.

## Branches

Each issue gets its own branch in this checkout, cut from the latest `main`; work happens
here, without worktrees. Before the first edit, run
`git switch main && git pull --ff-only github main`, then
`git switch -c <type>/<issue>-<topic>`. `main` tracks the `github` remote; `origin` is the
GitLab mirror, so pull and push through `github`. `main` changes only through a pull request.

Once the PR is open, delete the scratch files the work created (PR and comment body files,
temp scripts, logs) and stop the processes it started.

## Commits and pull requests

Commit messages follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):
`<type>(<scope>): <description>`, e.g. `fix(game): add new seats to the game's player collection`.
A breaking API change marks the type with `!` and adds a `BREAKING CHANGE:` footer.

Every PR body is the filled `.github/pull_request_template.md`, whoever opens the PR:

1. Copy the template to a file, fill it in, and pass it with `gh pr create --body-file <file>`.
2. Keep four parts, in this order:
   - `Closes #N`.
   - What changed and why, in 1 or 2 sentences.
   - `Evidence:` bullets, each a command and its result, or a test that failed before the fix and passes after.
   - `Confidence:` high, medium, or low, plus one sentence on the risk left.
3. Delete the template's `<!-- -->` comments. The body has at most 10 non-empty lines.

The diff already shows files and changes, so the body carries only the four parts. Session
notes, verification detail, follow-ups, and benchmarks go in a comment on the issue. Close the
issue once its acceptance criteria are met and that comment carries the verification.

Every PR adds its change to `CHANGELOG.md` under `## [Unreleased]`, in German, in the group
it fits (Hinzugefügt, Geändert, Entfernt, Behoben, Sicherheit, Tests), as
`- **Bereich**: Änderung (#N)`.

Commits, PR titles and bodies, and issue comments carry the human author only: no
`Co-Authored-By` trailer and no Claude or agent footer.

## Guardrails

Ask the user first before you run a migration against a non-test database, change
dependencies, or touch auth, roles, tokens, or credentials. Keep secrets,
tokens, and passwords out of logs, prompts, and commits.

## Agent skills

### Issue tracker

Issues live in GitHub Issues at `IliasAlmerekov/darts-backend`. See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
