---
name: planning-feature
description: Use when you have an approved spec or clear requirements for a multi-step backend change in this Symfony project and need an implementation plan before editing code.
---

# Planning Feature

Create implementation plans that are executable in this repository without extra discovery.

## Source Of Truth

- Read `AGENTS.md`, `app/AGENTS.md`, `app/composer.json`, `.gitlab-ci.yml`, the approved spec, and the affected code before writing the plan.
- Trust the repository over stale prose when they disagree.
- All shell commands in the plan must run through `rtk`.
- Treat the approved brainstorming spec as the reasoning baseline. The plan must preserve its task mode, invariants, failure thinking, and architecture decisions rather than flattening them into generic implementation tasks.

## Operating Assumption

- Work in the current branch.
- Do not require or suggest `git worktree`.
- If the task is risky, handle that with smaller tasks, clearer checkpoints, and explicit verification, not workspace isolation.

## When To Use

Use this skill when:

- a spec from local `brainstorming-feature` is approved
- the user gave clear requirements for a multi-step change
- implementation will touch multiple files, layers, or verification steps

Do not use this skill for:

- one-step mechanical edits
- bugfixes that can be executed safely without a formal plan
- vague requests that still need design clarification

## Plan Output

Save plans to:

- `docs/plans/YYYY-MM-DD-<topic>.md`

If the user prefers inline output, keep the same structure in chat.

Do not commit the plan file as part of planning.

## Planning Goals

A good plan for this repo must answer:

- what files and layers will change
- in what order the work should happen
- what tests must be added or updated
- whether a migration is required
- what exact Docker verification commands must run from the repository root
- what could break if the implementer gets the change wrong
- how the plan preserves invariants, compatibility, and the intended ownership boundaries
- how the work proves root cause removal for bugfixes instead of only suppressing symptoms

## Reasoning Inheritance

When a plan is based on a brainstorming spec, carry these forward explicitly:

- task mode
- current model and affected boundaries
- invariants that must remain true
- regression surface
- chosen architecture direction and rejected alternatives
- evidence, assumptions, and open questions that still matter during execution
- failure modes, rollback constraints, and scalability concerns when relevant

Do not collapse a high-quality spec into a shallow task list.
If the spec is missing these and the task is non-trivial, the plan should call
out the gap instead of pretending the design is settled.

## Scope Check

Before writing tasks, decide whether the work is:

- one coherent plan
- or several independent workstreams that should be split

Split the plan when separate workstreams could be implemented, reviewed, and verified independently.

## Planning Standard

Think like a senior engineer preparing another senior engineer to execute safely.

The plan should make clear:

- why the task order is correct
- where the behavioral risk really lives
- what must be proven before implementation is considered safe
- which step is intended to expose regressions early
- what should not be changed even if nearby code looks tempting

Prefer a plan that reduces uncertainty early over a plan that merely groups files
by folder or layer.

## TDD Planning Rules

This project uses TDD thinking for non-trivial changes.

When practical, structure the plan so that:

1. a failing or missing test exposes the target behavior or bug
2. implementation changes are made in the narrowest correct layer
3. focused tests are rerun before broad verification
4. full Docker verification happens after the behavior slice is complete

For bug work, the plan should identify:

- the test that proves the bug exists or existed
- the test that proves the root cause is fixed
- any adjacent regression tests needed for the same defect class

## Task Design Rules

Each task should be a meaningful checkpoint, not a 2-minute micro-step.

Good task boundaries for this project are usually:

- add or adjust tests for one behavior slice
- implement one service/repository/controller slice
- add one migration and its supporting code
- update one API contract and its documentation/serialization path
- run one verification batch

Within each task, be explicit about:

- exact files to create or modify
- the intended behavior change
- why this task belongs at this layer
- key implementation notes
- tests to run during the task
- risks and compatibility concerns
- what new confidence this task should create before moving forward

Tasks should expose a logical checkpoint such as one of:

- reproduce or lock down current behavior with tests
- implement one bounded behavior slice at the correct layer
- complete one contract or serializer path coherently
- complete one persistence or migration slice coherently
- close one class of risk, such as authorization or rollback safety

Avoid tasks that are vague buckets like `update business logic`, `fix bugs`, or
`handle edge cases`.

## Repository-Specific Planning Checklist

For each applicable area, make the plan explicit:

- task mode and architecture direction from the approved spec are preserved
- controllers remain thin
- DTO and validation updates are defined
- serializer and response contract impact is identified
- authorization and security checks are identified
- repository or query-path changes are identified
- transaction boundary and hot-path performance risks are identified
- Doctrine migration requirement is called out
- rollback or rollout handling is defined when persistence or contract changes are involved
- bug plans identify root cause coverage, not just observable symptom coverage
- regression-surface tests are identified when adjacent flows are at risk
- PHPUnit test coverage changes are listed
- Psalm and PHPCS verification are included
- CI-equivalent Symfony test flow is included

## Task Ordering Heuristics

Prefer this order when it fits the task:

1. lock down current or desired behavior with tests
2. change DTO, validator, contract, or serializer boundaries if required
3. change service or domain logic in the correct ownership layer
4. change repository, transaction, or schema behavior if required
5. update docs or API metadata that must stay in sync
6. run broad verification only after the slice is coherent

Deviate only when the repository structure or the approved spec makes another
order safer. If you deviate, say why.

## Plan Format

Use this structure:

```md
# <Feature Name> Implementation Plan

**Goal:** <one sentence>

**Inputs:**
- Spec: `docs/specs/<file>.md` or direct user requirements
- Key code paths: `<paths>`
- Task mode: `<feature | bug | refactor | contract-schema | performance>`

**Risks:**
- <compatibility / migration / contract / performance risks>

**Invariants:**
- <what must remain true>

**Regression Surface:**
- <adjacent flows, files, tests, or contracts likely at risk>

**Execution Notes:**
- <architecture boundary to preserve>
- <open assumptions or unknowns that execution must respect>

## Task 1: <name>

**Why**
- <purpose of this task>

**Files**
- Modify: `app/src/...`
- Create: `app/tests/...`

**Implementation Notes**
- <concrete guidance tied to this repo>
- <why this is the correct layer>
- <what not to change in this task>

**Tests**
- <exact focused test command(s), if any>

**Done When**
- <observable completion criteria>

## Task 2: <name>
...

## Verification

```bash
rtk docker compose up -d php mysql
rtk docker compose exec -T php bash -lc 'cd /var/www/html && mkdir -p build'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpcs'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php vendor/bin/psalm --show-info=false --report=build/psalm-quality-report.json'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php bin/console lint:yaml -v --ansi --env=test config'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console cache:clear --env=test'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpunit --coverage-text --exclude-group ignore --coverage-clover build/phpunit.coverage.xml --coverage-cobertura build/phpunit.coverage.cobertura.xml --log-junit build/phpunit.xml'
```
```

## Planning Heuristics

- Prefer task order that keeps tests leading the behavior change when practical.
- Name exact classes, methods, DTOs, and tests when they are already known from the codebase.
- Do not invent abstractions that the spec does not require.
- Do not lose the architecture reasoning from the spec when turning design into tasks.
- Do not turn a root-cause bugfix into a plan that only changes guards, null-checks, or exception handling unless the spec proved that is the correct fix.
- Do not hide important work in vague lines like "update logic" or "handle errors".
- Do not move verification outside Docker unless the user explicitly accepts that deviation.

## Self-Review

Before finishing the plan, check:

- every spec requirement maps to at least one task
- the plan preserves task mode, invariants, and architecture ownership from the spec
- bug plans prove root cause removal, not just symptom containment
- risks, rollback concerns, and regression-surface checks are assigned to real tasks
- no placeholders, TODOs, or vague implementation notes remain
- file paths are real and repo-relative
- migrations are called out when persistence changes
- verification commands are root-level and `rtk`-prefixed
- the plan assumes work in the current branch and does not mention worktrees

Fix issues inline before handoff.

## Git Rule

- Writing the plan file is allowed.
- Committing the plan file during planning is not allowed.
- Plan files stay uncommitted until the user explicitly asks for a commit or a later workflow handles commits.

## Reviewer Prompt

If you want an independent review of the finished plan before execution, use `plan-reviewer-prompt.md` in this folder.
