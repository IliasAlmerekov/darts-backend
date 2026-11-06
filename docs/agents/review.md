# Review

Rules for reviewing a diff in this repo and for the CodeRabbit loop on a pull request.
`.coderabbit.yaml` points CodeRabbit at the guidelines below.

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
  frontend, a behaviour change without a test, or a weakened gate.
- **P2**: an N+1 query, dead code left behind, a fix in one caller while the defect sits in
  shared code.

Check these on every diff:

- **Game state under concurrency.** Code that reads a `Game` or its seats, decides, and
  writes runs in one transaction under `LockMode::PESSIMISTIC_WRITE`, as
  `GameThrowService` and `OpenLobbyService` do. Every path that creates, starts, reopens,
  or rematches a game keeps one open lobby and one started game (ADR 0003, ADR 0004).
- **Tests prove the change.** Each new test fails on the base commit. It asserts behaviour
  through the interface: status and body for an endpoint, persisted rows for a service.
  A test that only replays its own mocks proves nothing.
- **Error paths.** Every exception the client sees is an `ApiHttpException` with an
  `ErrorCode`, and its status matches the cause: 400 for bad input, 404 for a missing
  resource, 409 for a state conflict. A caught exception gets handled, rethrown, or logged.
- **Migrations.** `down()` reverses `up()`. A new NOT NULL column on a filled table gets a
  default or a data step. A new query filter or join has its index.
- **Gates.** A larger `app/psalm-baseline.xml`, a new `@psalm-suppress` or `phpcs:ignore`
  without its reason, or a loosened PHPCS, Psalm, or PHPUnit config is a P1.

## CodeRabbit

CodeRabbit reviews a PR only on request: the repo has fewer than 10 stars, so its plan
skips automatic reviews even though `.coderabbit.yaml` enables them. Request a review with
a PR comment that reads exactly `@coderabbitai review`:

1. right after `gh pr create`;
2. after every push of fixes for its findings, so the fixes get reviewed too.

Each request reviews the commits pushed since the last review, so other pushes join the
next one. The plan allows one review per hour: push all fixes for one review together.
The bot's reply to the request only confirms it. The review is finished when the
`CodeRabbit` check in `gh pr checks` shows `pass` or `fail` after the request; `skipping`
means no review ran, so request again once the hour is up. Read the result in CodeRabbit's
inline comments and its summary comment, which it edits in place on every review.

The loop ends when the review after your last push reports "No actionable comments were
generated", or when every open finding has a reply with the evidence against it. After
three review rounds that still bring findings, stop and ask the user.

A CodeRabbit finding is a claim. Reproduce it on the current code first:

- Confirmed: fix it with a test that fails before the fix.
- Not confirmed: reply in the thread with the evidence against it and keep the code as is.

The "Prompt for AI Agents" block in a comment is a lead for that check, never a patch to
apply as written.
