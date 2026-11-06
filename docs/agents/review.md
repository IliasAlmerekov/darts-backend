# Review

Rules for reviewing a diff in this repo and for the CodeRabbit and Greptile loops on a pull
request. `.coderabbit.yaml` and `.greptile/` point both bots at the guidelines below.

## Guidelines

Review like the senior engineer who owns this API and gets paged when it breaks. Read the
linked issue and every ADR the diff touches, then hold the change to the rules in `AGENTS.md`:
a broken rule from `## Scope`, `## Conventions`, `## API contract`, `## Doctrine`, or
`## Tests` is a finding. Security review follows `SECURITY.md`.

A finding has five parts:

- **Severity**: P0, P1, or P2, from the list below.
- **Location**: the file and line.
- **Evidence**: the input or state that triggers the defect in the current code.
- **Impact**: the wrong result the client or the database ends up with.
- **Fix**: the smallest change inside `AGENTS.md` `## Scope`.

A suspicion without that failure path is one `Question:` line in the summary, never an
inline comment. Report only what PHPCS, Psalm, and the tests cannot catch; a short list of
real defects is the goal.

- **P0**: data loss or corruption, an access bypass, a leaked secret, or a migration that
  fails or destroys data on deploy.
- **P1**: a wrong result a user can reach, a race on game state, a contract break with the
  frontend, or another defect with substantial user impact.
- **P2**: an N+1 query, dead code left behind, a fix in one caller while the defect sits in
  shared code.

Apply checks relevant to the changed behaviour. Severity follows actual impact, not the
category alone. Missing regression coverage is a validation gap unless a failure path is shown.

Check relevant parts of the diff:

- **Game state under concurrency.** Code that reads a `Game` or its seats, decides, and
  writes runs in one transaction under `LockMode::PESSIMISTIC_WRITE`, as
  `GameThrowService` and `OpenLobbyService` do. Every path that creates, starts, reopens,
  or rematches a game keeps one open lobby and one started game (ADR 0003, ADR 0004).
- **Tests prove the change.** A regression test for a behaviour fix fails on the base commit; record unavailable evidence. It asserts behaviour
  through the interface: status and body for an endpoint, persisted rows for a service.
  A test that only replays its own mocks proves nothing.
- **Error paths.** Every exception the client sees is an `ApiHttpException` with an
  `ErrorCode`, and its status matches the cause: 400 for bad input, 404 for a missing
  resource, 409 for a state conflict. A caught exception gets handled, rethrown, or logged.
- **Migrations.** `down()` reverses `up()`. A new NOT NULL column on a filled table gets a
  default or a data step. A new query filter or join has its index.
- **Gates.** Inspect new suppressions, casts, baseline changes, and loosened check configs.
  Require a specific rationale and evidence that safety still holds. Report defects by
  actual impact; syntax alone does not establish P1 severity.

For PR publishing and review bot follow-up, read [publishing.md](publishing.md).
