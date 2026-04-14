---
name: subagent-development
description: Use when executing an approved plan in this Symfony backend through a fixed multi-agent pipeline in the current branch with TDD and explicit verification gates.
---

# Subagent Development

Execute approved work through a fixed local agent pipeline for this repository.

## Source Of Truth

- Read `AGENTS.md`, `app/AGENTS.md`, `app/composer.json`, `.gitlab-ci.yml`, the approved spec, the approved plan, and the affected code before dispatching agents.
- Read `.codex/context/current-focus.md`, `project-map.md`, `domain-map.md`, and `decisions.md` as the first-pass memory layer for new sessions.
- Trust the repository over stale prose when they disagree.
- All shell commands must go through `rtk`.

## Operating Assumption

- Work in the current branch.
- Do not require, suggest, or create `git worktree`.
- Use smaller scoped tasks and explicit gates instead of workspace isolation.

## Required Inputs

Before using this skill, have:

- an approved spec for `normal` or `complex` work, or explicit approved inline design for `tiny`
- an implementation plan from local `writing-plans`

Do not start coding before the plan exists.

## Execution Standard

Execution must preserve the depth established during brainstorming and planning.

Do not reduce the approved spec and plan to:

- generic file-edit tasks
- symptom-fixes for bug work
- shallow test passes without regression thinking
- local success claims that skip the final Docker verification gate

Every agent should preserve:

- task mode
- invariants
- architecture boundaries
- regression surface
- root-cause expectations for bug work
- failure-mode, rollback, and compatibility constraints when relevant

## Fixed Agent Pipeline

Use these local agents in this order unless the task is tiny enough to skip optional gates:

1. `researcher`
2. `coder`
3. `reviewer`
4. `tester`
5. `security`
6. `architect`
7. `explorer`

## Agent Responsibilities

The lead agent should treat each role as a distinct gate with a specific burden
of proof. A handoff is incomplete if it only says "done" without evidence.

### 1. Researcher

- Model target: `gpt-5.3-codex-spark`
- Purpose: collect context only
- Must not propose fixes, improvements, refactors, or solutions
- Must map relevant files, boundaries, dependencies, affected tests, and likely touch points
- Must surface current behavior, current ownership by layer, invariants, and regression surface
- Best used before implementation and whenever the lead agent needs more context

### 2. Coder

- Model target: `gpt-5.4`
- TDD is mandatory
- Implements only the scoped task
- Writes or updates the tests needed for the behavior change
- Runs focused verification during the task
- Must preserve architecture ownership from the spec and plan
- Must fix root cause for bug work, not only suppress the observable symptom
- Must stop and surface the gap if the plan is insufficient to execute safely

### 3. Reviewer

- Model target: `gpt-5.4`
- Reviews coder output for correctness, plan alignment, regressions, and code quality
- Findings first, concise, actionable
- Does not silently broaden scope
- Must explicitly check for wrong-layer fixes, broken invariants, contract drift, and symptom-fix patterns

### 4. Tester

- Model target: `gpt-5.4`
- Independently exercises what coder changed
- Runs targeted tests and scenario checks
- Reports failures, gaps, flaky behavior, and missing coverage
- Does not patch production code as part of validation
- Must test negative paths and immediate regression surface, not only the happy path

### 5. Security

- Model target: `gpt-5.4`
- Checks for secrets, unsafe input handling, auth gaps, insecure serialization, query safety, and other vulnerabilities
- Reports security risks and missing mitigations
- Does not waive issues silently
- Must treat authorization, validation, logging, and error leakage as first-class checks, not optional hardening

### 6. Architect

- Model target: `gpt-5.4`
- Verifies architectural fit: controller/service/repository boundaries, DTO usage, response contract discipline, migration discipline, transaction and layering rules
- May be used earlier for ambiguous structural decisions, but at minimum is a post-implementation gate
- Must reject speculative abstraction when the approved design did not require it
- Must reject local fixes that land in the wrong ownership layer even if they appear to work

### 7. Explorer

- Model target: `gpt-5.4`
- Final integration verifier
- Runs local CI-equivalent checks from the repository root
- Confirms whether the whole change is ready or blocked
- Final verdict owner
- Must not inherit optimistic assumptions from previous agents
- Must treat skipped required verification as a blocker, not a soft note

## Lead Agent Protocol

`lead_orchestrator` owns the execution contract.

Before dispatching implementation:

1. restate the approved task slice
2. restate task mode, invariants, and regression surface
3. name the exact task from the plan being executed now
4. decide which gates are mandatory for this slice and why
5. keep non-overlapping work only; do not create overlapping coding ownership

During execution:

- do not let coder run ahead of missing context or unresolved blockers
- do not treat a passing focused test as final success
- loop back to coder only on concrete findings, not vague discomfort
- keep each retry scoped to the actual blocking finding

At closure:

- summarize evidence from each gate
- state whether anything was skipped and why
- allow completion only after explorer gives a final verdict or exact blocker set

## TDD Rules

- No production code before a failing test for the intended behavior when practical.
- Coder should lead with targeted tests for each planned slice.
- Tester validates independently after coder and reviewer.
- Explorer runs the full verification batch only after prior gates are complete.
- For bug work, at least one test should prove the bug existed or the broken behavior path was covered, and another should prove the root cause is fixed or guarded against regression.

## Dispatch Rules

- Give each agent only the context it needs.
- Prefer fresh agents over reusing long-running ones for unrelated tasks.
- Do not run multiple coding agents in parallel against overlapping files.
- Parallelize only context gathering or non-overlapping read-only checks when safe.
- When the task is risky, prefer narrower execution slices over broader parallelism.

## Task Packet Standard

When dispatching coder, reviewer, tester, security, architect, or explorer, the
lead agent should pass a task packet with:

- task name
- task mode
- approved scope boundaries
- invariants to preserve
- current regression surface
- files expected to change or be checked
- focused commands already run
- open findings or assumptions still in play

Do not hand off only a file list without the behavioral context.

## Required Handoffs

### Researcher -> Coder

Pass:

- approved task text
- relevant file map
- current behavior summary
- current ownership by layer
- invariants and regression surface
- affected tests and contracts
- open unknowns only

Do not pass design suggestions from researcher because researcher must not produce them.

### Coder -> Reviewer

Pass:

- task objective
- changed files
- tests added or updated
- focused verification run by coder
- assumptions made during implementation
- any deviations from the plan and why

### Reviewer -> Tester

Pass:

- approved change scope
- reviewer findings that need validation attention
- invariants or regressions that need explicit test pressure

### Tester -> Security -> Architect

Pass only validated scope and discovered risks. Do not re-open unrelated areas.

### Architect -> Explorer

Pass:

- whether architecture is acceptable
- any must-fix structural blockers
- any boundaries explorer should watch when interpreting verification failures

## Gate Criteria

The lead agent should treat these as minimum pass conditions:

- `researcher`: current behavior, boundaries, impact surface, and unknowns are concrete
- `coder`: task implemented with tests and focused verification evidence
- `reviewer`: no unresolved correctness or plan-alignment blockers
- `tester`: target behavior and immediate regression surface validated
- `security`: no unresolved security blockers in changed surface
- `architect`: no unresolved wrong-layer, contract, migration, or abstraction blockers
- `explorer`: required Docker verification run and reported with exact pass/fail status

## Failure Escalation

If a gate fails:

- name the exact blocker
- send the work back only to the role that can resolve it
- preserve prior valid findings instead of restarting the whole pipeline
- do not bypass a failing gate just to keep momentum

If the approved plan is insufficient, pause execution and return to planning
rather than inventing architecture during coding.

## Context Update Rule

After meaningful execution work, update the context layer before closing the
session:

- always update `.codex/context/changelog.md`
- update `.codex/context/current-focus.md` when next steps or active focus changed
- update `.codex/context/backlog.md` when status changed
- update `.codex/context/decisions.md`, `project-map.md`, or `domain-map.md` only when the change truly affects them

## Final Verification

Explorer should use these root-level commands when applicable:

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

## Exit Criteria

Do not claim the task is done until:

- coder completed scoped implementation
- reviewer accepted the code or all findings were fixed
- tester validated the change
- security found no unresolved blockers
- architect found no unresolved structural blockers
- explorer passed the required local verification or clearly reported the blocking failures
- the final report states what was skipped, if anything, and why

## Tiny Work Shortcut

For tiny tasks, the lead agent may compress the pipeline:

- `researcher -> coder -> reviewer -> explorer`

Only skip `tester`, `security`, or `architect` when the task clearly does not justify them, and state the reason.
