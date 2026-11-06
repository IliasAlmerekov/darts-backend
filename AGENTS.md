# Darts App (backend)

Symfony 8 / PHP 8.4 API for the darts game: game lifecycle, throws, statistics,
invitations, registration, security. The application root is `app/`
(`src/`, `tests/`, `config/`, `migrations/`). The frontend lives in a separate
repository and consumes this API.

## Domain

Before changing game, lobby, or login behaviour, read `GLOSSARY.md` and the ADRs in
`docs/adr/` that touch the area. They record rules the code enforces, such as one open
lobby and one started game.

Before adding a class or moving code between controller, service, repository, and entity,
read `docs/architecture.md`. It says which layer owns what and where new code goes.

## Scope

Solve the whole task with the least new code. Before the first edit, list every place the
change must reach: callers, tests, fixtures, config, serializer groups, API docs. That list
is the scope; features nobody asked for are outside it, named in one line in the report.

Reach for the first option that works: no code at all, then a helper or service already in
`app/src`, then Symfony or PHP built-ins, then an installed package, then one line a reader
gets at a glance, then new code.

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

## Workflow

Size the task before the first edit. When the size is unclear, treat the task as large.

- **Small**: one change with nothing to decide, such as a typo, a config value, or a bug
  whose cause you already know. Cut the branch, write one failing test at the interface the
  change touches, make the change under `## Scope`, and run the verification.
- **Large**: open design decisions, more than one module, or an area under `## Guardrails`.
  Grill the plan with the user through `/grill-with-docs`; when you start the grill
  yourself, call the `grilling` and `domain-modeling` skills. Once every decision is
  settled, write the plan as numbered subtasks, each with acceptance criteria. Build each
  subtask with the `tdd` skill; its acceptance criteria are the agreed seams. Finish with
  the `code-review` skill against `main`.

`code-review` treats `## Scope` as a repo standard: a smell whose fix adds an abstraction
the task does not need goes in the report as a note, not as a finding.

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
  `HttpExceptionInterface`) with a code from `ErrorCode`. `App\Controller\ErrorController`
  renders its code, message, and status, and hides other exceptions behind a generic body.
- Optional `UserInterface` methods are guarded with `method_exists(...)` unless the code
  already depends on `App\Entity\User`.
- `FormErrorIterator` is iterable directly, without `getIterator()`.
- A `@psalm-suppress` for a promoted constructor property goes in the class docblock, with
  its reason; Psalm 6 skips it on the property and on the constructor.
- Frontend URLs and redirects come from configuration such as `FRONTEND_URL`.

## API contract

Response shape is a contract with the frontend, which lives next to this repo in
`../dartsapp`. Before changing a route or a response, grep its path in `../dartsapp/src`
and list the consumers in the PR. Serializer groups and the Nelmio API docs change in the
same commit as the response. The frontend validates responses against a dump of those docs,
`../dartsapp/docs/backend-api-contract.json`, so a changed response also gets a frontend
issue to refresh that dump.

The frontend vendors `docs/contract/throw-scoring-cases.json`. A change to `OutRule` or
`ThrowScoring` updates that file in the same PR and gets a frontend issue to refresh its copy.

## Doctrine

Every schema change ships with a migration in `app/migrations`. Generate it against this
checkout's test database, which holds exactly the branch's migrations; the dev database is
shared with other sessions. Then add the license header and review the SQL:

```bash
scripts/php.sh php bin/console doctrine:migrations:migrate --env=test --no-interaction
scripts/php.sh php bin/console doctrine:migrations:diff --env=test
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

While you work, run only the tests you touch, from the repo root. `scripts/php.sh` runs a
command in the PHP container that serves this checkout; in a worktree that container uses
the worktree's own test database, which `scripts/worktree.sh new` creates and migrates:

```bash
scripts/php.sh php vendor/bin/phpunit --filter <TestClass>
```

Run the full verification below once, before the commit.

## Verification

Before the commit, run `scripts/verify.sh` from the repository root. It runs the checks of
`.github/workflows/ci.yml`, the gate on every PR, and stops at the first failure; its header
says how it picks the container. Host-local `php`, `composer`, or `vendor/bin/*` runs only
when the user accepts the deviation.

Locally backend and frontend run as one Compose stack, the project `darts`: nginx on :8001,
php, mysql, and the frontend on :5173. Start it with `docker compose up -d` in the
workspace folder that holds both repos. The php container mounts the main checkout's `app/`.

When `check-test-db-env.sh` fails, put the `DATABASE_URL` with the root password from
`docker-compose.yaml` in `app/.env.test.local`. Raise `MIN_PHP_VERSION` in
`scripts/check-runtime-image.sh` with each PHP security release.

A green result comes from fixing the code. The Psalm, PHPCS, and PHPUnit configs and
`app/psalm-baseline.xml` change only on purpose: the baseline only shrinks, and a
deliberate config change, such as excluding the slow `benchmark` group from the default
PHPUnit run, ships with its reason in the commit and a test that pins it.

## Branches

`main` tracks the `github` remote; `origin` is the GitLab mirror, so pull and push through
`github`. `main` changes only through a pull request.

Each issue gets its own branch in its own worktree: several sessions share the main checkout,
so its branch stays where it is. Start with `scripts/worktree.sh new <type>/<issue>-<topic>`.
It cuts the branch from the latest `main` into `../worktrees/<repo>-<issue>`, copies the local
`.env` files, installs Composer packages, migrates the worktree's test database, and prints
the path. Work, test, and commit there.

Once the PR is open, delete the scratch files the work created (PR and comment body files,
temp scripts, logs) and stop the processes it started. Once it is merged, and at the start of
every new task, run `scripts/worktree.sh prune`. It removes each worktree, its test database, and
each local branch whose PR is merged, and lists what it kept.

## Commits and pull requests

Commit messages follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):
`<type>(<scope>): <description>`, e.g. `fix(game): add new seats to the game's player collection`.
A breaking API change marks the type with `!` and adds a `BREAKING CHANGE:` footer.

Every PR body is the filled `.github/pull_request_template.md`; its comments say what each
part holds. Pass it with `gh pr create --body-file <file>`. Session notes, verification detail,
follow-ups, and benchmarks go in a comment on the issue. Close the issue once its acceptance
criteria are met and that comment carries the verification.

Every PR adds its change to `CHANGELOG.md` under `## [Unreleased]`, in German, in the group
it fits (Hinzugefügt, Geändert, Entfernt, Behoben, Sicherheit, Tests), as
`- **Bereich**: Änderung (#N)`.

Commits, PR titles and bodies, and issue comments carry the human author only: no
`Co-Authored-By` trailer, no agent footer, no `triage` skill AI disclaimer.

## Review

Before you review a diff, and after `gh pr create` or a push to an open PR, read
`docs/agents/review.md`. It holds the severities, the checks for every diff, and the
`@coderabbitai review` request loop.

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
