---
name: brainstorming-feature
description: Use when shaping a feature, behavior change, API contract change, schema change, or non-trivial refactor in this Symfony backend before implementation.
---

# Brainstorming Feature

Turn a backend request into an implementation-ready design for this repository.

## Source Of Truth

- Read `AGENTS.md`, `app/AGENTS.md`, `app/composer.json`, `.gitlab-ci.yml`, the affected code, and neighboring tests before proposing a design.
- Trust the repository over stale prose when they disagree.
- Keep all command examples aligned with this repo's execution policy: run shell commands through `rtk`.

## When To Use

Use this skill for:

- new endpoints or response contract changes
- behavior changes in controllers, DTOs, services, repositories, or serializers
- Doctrine schema changes or query-path redesign
- validation, authorization, or security-sensitive work
- non-trivial refactors that cross layer boundaries

Do not use this skill for:

- typo fixes and wording-only docs edits
- purely mechanical changes with no design choice
- narrow bugfixes where the root cause and fix are already obvious

## Desired Outcome

Before implementation, produce one of these:

- `tiny` design: short approved design in chat
- `normal` design: approved spec in `docs/specs/YYYY-MM-DD-<topic>.md`
- `complex` design: approved spec plus decomposition and follow-up planning notes

Default to `normal` when unsure.

## Task Modes

Classify the work not only by size, but also by problem type:

- `feature`: new capability, endpoint, rule, or workflow
- `bug`: defect, regression, inconsistency, or broken edge case
- `refactor`: structural cleanup with behavior preserved
- `contract-schema`: API contract, serializer, DTO, entity, or migration-driven change
- `performance`: hot-path, query, latency, throughput, or load-related change

State the task mode explicitly before proposing a design. Use the mode to decide
which questions and risks matter most.

## Scope Classification

### `tiny`

Use when all of the following are true:

- one narrow change
- no schema change
- no public API contract change
- no cross-layer redesign

### `normal`

Use when any of the following are true:

- one feature or bug spans multiple classes or layers
- request or response shape changes
- validation or authorization rules change
- test strategy needs to shift

### `complex`

Use when any of the following are true:

- multiple subsystems must be sequenced
- migration plus contract change plus behavioral change land together
- performance, consistency, or rollout trade-offs need explicit treatment

If a request is too large for one spec, decompose it and brainstorm the first slice.

## Process

1. Explore the current context.
   - Read the relevant policy files, implementation, tests, and recent commits.
   - Map the current boundaries: controller, DTO, validator, service, repository, entity, serializer.
   - Note any repo inconsistencies that matter for planning.
2. Build the current-system model.
   - Identify the current entry points, data flow, persistence path, side effects, and neighboring tests.
   - State what layers own the current behavior and which files are likely to be touched.
   - Identify invariants that must remain true after the change.
   - Identify what could regress if the change is done at the wrong layer.
3. Classify the scope as `tiny`, `normal`, or `complex`, and name the task mode.
4. Ask targeted questions.
   - Ask one question at a time by default.
   - You may ask up to three tightly related questions in one message when they unblock the same decision.
   - Focus on purpose, constraints, compatibility, success criteria, and failure tolerance.
   - Ask like a senior engineer trying to remove ambiguity, not like a form collector trying to fill a template.
5. Present approaches when the design is not obvious.
   - Offer two or three approaches.
   - Lead with the recommended option and explain the trade-off.
   - State why the recommendation is correct now, why the other options lose, and what future conditions would change the decision.
6. Present the design.
   - Scale the detail to the scope.
   - Cover only the sections that matter, but include all applicable backend concerns from the checklist below.
7. Persist the approved design.
   - `normal` and `complex`: write the spec to `docs/specs/YYYY-MM-DD-<topic>.md`.
   - `tiny`: keep it inline unless the user asks to persist it or the decision history matters.
   - Do not commit the spec file as part of brainstorming.
8. Self-review the design before handoff.
9. Ask the user to approve the design or spec before implementation.
10. Hand off to planning.
   - If the local `planning-feature` skill exists, use it.
   - Otherwise write a short execution plan to `docs/plans/YYYY-MM-DD-<topic>.md` or inline before coding.

## Reasoning Standard

Think like a senior backend engineer.

Before proposing a design, make the following explicit:

- current behavior and current boundaries
- invariants that must remain true
- affected contracts, persistence paths, side effects, and integration points
- failure modes, negative paths, and rollback constraints
- scalability, load, query, and hot-path risks
- evidence, assumptions, and unknowns

Do not jump from symptom to fix.

For bugs and regressions, identify the root cause and explain why the proposed
change removes the cause rather than hiding the symptom.

If a key point is inferred rather than proven from the code, label it clearly as
an assumption or an open question.

## Senior Question Ladder

Use these question categories to drive discovery. Do not ask all of them
mechanically. Ask the smallest set that resolves real design ambiguity.

1. Why does this change exist, and what outcome counts as success?
2. What must not break for existing clients, data, or operations?
3. How does the current path work today, end to end?
4. Where is the correct ownership boundary for this behavior?
5. What invariant, contract, or guarantee must stay true after the change?
6. What happens on invalid input, stale state, duplicates, retries, and partial failure?
7. What happens under load, larger data volume, or future adjacent features?
8. What rollout, migration, or rollback constraints exist?
9. How will we prove the change fixed the cause and did not introduce regressions?

## Bug Mode

When the request is a bug, regression, or inconsistent behavior:

1. Describe the symptom precisely.
2. Describe the reproduction path or the best-known triggering conditions.
3. State the violated invariant or incorrect behavior.
4. Trace the current code path to the most likely root cause.
5. Explain why the root cause is the cause, not just a correlated symptom.
6. Identify the smallest correct fix at the correct layer.
7. Name adjacent scenarios that could fail from the same class of defect.
8. Define tests that prove the cause is removed, not merely masked.

Do not propose a fix until steps 1-4 are explicit.

## Required Design Snapshot

Before the final spec or approval request, provide a concise snapshot that makes
the reasoning legible:

- `Task Mode`
- `Scope`
- `Current Model`
- `Invariants`
- `Regression Surface`
- `Options`
- `Recommendation`
- `Evidence / Assumptions / Unknowns`
- `Open Questions`

## Backend Design Checklist

For each applicable item, make the decision explicit:

- goal, non-goals, and compatibility expectations
- current behavior and current ownership by layer
- invariants that must stay true
- affected entry points: controller, command, event subscriber, messenger handler, cron, or service
- request DTO shape and validation rules
- authorization, security guards, and failure modes
- response contract, serializer implications, and API doc impact
- service boundaries and where business logic should live
- repository/query changes, transaction boundaries, and hot-path risks
- Doctrine entity or schema impact
- migration requirement
- rollout, backward compatibility, and rollback constraints
- error mapping and HTTP status codes
- observability or debugging impact when relevant
- regression surface: adjacent flows, files, and tests likely at risk
- tests to add or update
- CI-equivalent verification plan

## Repository-Specific Rules

- Keep controllers thin. Put business logic in services and persistence logic in repositories.
- Do not bind request payloads directly to Doctrine entities.
- Prefer DTOs and Symfony Validator attributes for input validation.
- Treat API output as a contract. Call out serializer and Nelmio API doc impact when output changes.
- Every schema change needs a migration and test impact analysis.
- Watch for N+1 queries, accidental lazy loading, and oversized transaction scopes on hot paths.
- Preserve existing style conventions from the codebase, including strict types and the current comparison style.
- Prefer the smallest change at the correct layer over defensive patches that only suppress symptoms.

## Failure Thinking

For `normal` and `complex` work, explicitly think through the likely failure
edges:

- invalid or malicious input
- stale state and concurrent writes
- duplicate requests, retries, and re-entrancy
- partial database or downstream failures
- serializer drift or contract regressions
- migration, rollout, and rollback hazards

If these do not matter for the task, say so briefly rather than ignoring them.

## Scalability And Evolution

For design choices that may survive future work, explicitly assess:

- hot paths and likely query count impact
- whether the design survives expected near-term feature growth
- where extension points should live
- what should explicitly not be abstracted yet

Do not add speculative abstractions just to look future-proof. Design for the
next realistic changes, not imaginary ones.

## Self-Review

Before asking for approval, check the design for:

- unclear current-system model or missing ownership boundaries
- missing invariants, failure modes, or regression-surface analysis
- a fix that treats a symptom without proving the root cause
- facts, assumptions, and open questions mixed together
- placeholders, TODOs, or unresolved ambiguity
- contradictions with the current code, Composer config, or CI flow
- missing DTO, validation, serializer, security, or migration considerations
- breaking API behavior that is not called out explicitly
- vague testing or verification language

Fix issues inline. Do not carry ambiguity into planning.

## Git Rule

- Writing the spec file is allowed.
- Committing the spec file during brainstorming is not allowed.
- Spec files stay uncommitted until the user explicitly asks for a commit or a later workflow handles commits.

## Planning Output

When the design moves to planning, the plan should identify:

- files and layers expected to change
- whether migrations are required
- exact tests to add or update
- exact Docker verification commands to run from the repository root

Use these root-level command forms in plans when they apply:

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

## Spec Template

Use this outline for `normal` and `complex` specs:

```md
# <Title>

## Goal

## Non-Goals

## Task Mode

## Current Behavior

## Invariants

## Regression Surface

## Proposed Design

## Alternatives Considered

## Validation And Security

## Persistence And Data Impact

## Failure Modes And Rollback

## Scalability And Evolution

## Error Handling

## Evidence, Assumptions, And Unknowns

## Tests

## Verification

## Open Questions
```

## Reviewer Prompt

If you want an independent review of the spec before planning, use `spec-reviewer-prompt.md` in this folder.
