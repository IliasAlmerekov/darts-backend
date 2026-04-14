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
- an implementation plan from local `planning-feature`

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
2. `architect` (pre-implementation fit and ownership gate)
3. `coder` (implementation slice A, when safe)
4. `coder` (implementation slice B, when safe)
5. `reviewer`
6. `tester`
7. `security`
8. `explorer`
9. `lead_orchestrator` final completion decision

Default execution means:

- the lead agent is the only orchestration owner
- `architect` runs before implementation to pressure-test layering, ownership, and whether dual-coder execution is safe
- the two `coder` agents are optional parallel leaf agents with shared task context but strictly disjoint ownership
- if ownership is not cleanly disjoint, the lead agent must use one coder or serialize the slices instead of forcing two coders
- `reviewer` and `tester` form a joint post-implementation validation gate
- `security` runs only after both `reviewer` and `tester` explicitly pass
- `explorer` is the final integration verifier, not the workflow owner
- `lead_orchestrator` is the only role allowed to declare the task complete after all mandatory gates pass

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
- Already a subagent for this workflow; treat coder as a leaf execution agent
- Must not spawn, delegate to, or request additional subagents, workers, or parallel agents
- TDD is mandatory
- Implements only the scoped task
- Writes or updates the tests needed for the behavior change
- Runs focused verification during the task
- Must preserve architecture ownership from the spec and plan
- Must fix root cause for bug work, not only suppress the observable symptom
- Must stop and surface the gap if the plan is insufficient to execute safely
- When paired with another coder, must respect explicit ownership boundaries and avoid editing files owned by the other coder unless the lead agent reassigns ownership

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
- Runs before implementation by default for `normal` and `complex` work
- Verifies architectural fit: controller/service/repository boundaries, DTO usage, response contract discipline, migration discipline, transaction and layering rules
- Must pressure-test the proposed ownership split before any dual-coder execution starts
- Must reject a two-coder split when shared files, shared types, or cross-layer coordination make ownership overlap likely
- Must reject speculative abstraction when the approved design did not require it
- Must reject local fixes that land in the wrong ownership layer even if they appear to work

### 7. Explorer

- Model target: `gpt-5.4`
- Final integration verifier
- Runs local CI-equivalent checks from the repository root
- Reports whether verification evidence is ready for handoff or blocked
- Not the final workflow owner
- Must not inherit optimistic assumptions from previous agents
- Must treat skipped required verification as a blocker, not a soft note

## Lead Agent Protocol

`lead_orchestrator` owns the execution contract.

Before dispatching implementation:

1. restate the approved task slice
2. restate task mode, invariants, and regression surface
3. name the exact task from the plan being executed now
4. run `architect` as a pre-implementation gate for ownership fit and layering risk
5. declare the ownership plan before any coder starts
6. split the implementation stage into two coder packets only if ownership is explicitly non-overlapping
7. if ownership intersects, shared files are unavoidable, or the slice crosses too many layers, refuse the dual-coder split and use one coder or serialized slices
8. decide which gates are mandatory for this slice and why

During execution:

- do not let coder run ahead of missing context or unresolved blockers
- do not let coder spawn subagents; coder is already a subagent in this workflow
- do not treat a passing focused test as final success
- send reviewer or tester failures back to coder only as concrete findings, not vague discomfort
- normalize reviewer and tester failures into a precise fix packet with file paths, failing tests or commands, and why the issue blocks progress
- allow at most 2 coder retry loops for the same slice after reviewer/tester failures
- after the retry limit, pause execution and escalate to `lead_orchestrator` for a human checkpoint instead of looping indefinitely
- keep each retry scoped to the actual blocking finding

At closure:

- summarize evidence from each gate
- state whether anything was skipped and why
- allow completion only after `lead_orchestrator` reviews the full gate evidence, including `explorer`

## TDD Rules

- No production code before a failing test for the intended behavior when practical.
- Each coder should lead with targeted tests for its owned slice.
- Reviewer and tester validate the merged coder output as a joint gate after both coders finish their owned slices.
- Explorer runs the full verification batch only after prior gates are complete.
- For bug work, at least one test should prove the bug existed or the broken behavior path was covered, and another should prove the root cause is fixed or guarded against regression.

## Dispatch Rules

- Give each agent only the context it needs.
- Prefer fresh agents over reusing long-running ones for unrelated tasks.
- `lead_orchestrator` is the only agent allowed to spawn or coordinate other agents for this skill.
- Treat `coder`, `reviewer`, `tester`, `security`, `architect`, and `explorer` as leaf agents. If they need help, they must return a blocker to the lead agent instead of delegating further.
- For `normal` and `complex` work, use two coder agents in parallel only when ownership is disjoint and `architect` explicitly approves the split.
- Do not run multiple coding agents in parallel against overlapping files.
- If one file must change, assign one coder as the explicit owner of that file and keep the second coder out of it unless the lead agent reassigns the file.
- Reviewer and tester may run in parallel on the integrated coder output, but both must report an explicit verdict before work can advance.
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

## Canonical Lead Ownership Packet

When `lead_orchestrator` dispatches coder A and coder B, use this exact compact structure:

```text
Task: <exact plan slice>
Mode: <tiny|normal|complex>
Scope: <what is in / out>
Invariants: <must-stay-true list>
Regression Surface: <tests/contracts/flows to protect>
Architect Verdict: <PASSED, split Safe|Unsafe>

Coder A Ownership
- Files/Layers:
- Allowed Changes:
- Must Not Touch:
- Focused Tests/Commands:

Coder B Ownership
- Files/Layers:
- Allowed Changes:
- Must Not Touch:
- Focused Tests/Commands:

Shared Rules
- No nested subagents
- No ownership crossing without lead reassignment
- Report blockers with exact file paths and failing commands/tests
- Retry loop count for this slice: <0|1|2>
```

If the `Architect Verdict` is `Unsafe`, do not emit coder B ownership. Replace it with:

```text
Execution Shape: single-coder or serialized slices required
Reason: <why ownership overlaps>
```

Example:

```text
Task: Optimize deprecated full undo snapshot assembly
Mode: normal
Scope: Service/Game snapshot assembly only; no API contract changes
Invariants: Undo semantics unchanged; GameResponseDto contract unchanged
Regression Surface: GameThrowServiceTest, GameServiceTest, undo controller flow
Architect Verdict: PASSED, split Safe

Coder A Ownership
- Files/Layers: app/src/Service/Game/GameService.php, app/tests/Service/Game/GameServiceTest.php
- Allowed Changes: snapshot/history assembly logic and matching tests
- Must Not Touch: GameThrowService, controller contract, repository interfaces
- Focused Tests/Commands: GameServiceTest targeted phpunit filter

Coder B Ownership
- Files/Layers: app/src/Service/Game/GameThrowService.php, app/tests/Service/Game/GameThrowServiceTest.php
- Allowed Changes: undo orchestration and matching tests
- Must Not Touch: GameService snapshot assembly internals unless reassigned
- Focused Tests/Commands: GameThrowServiceTest targeted phpunit filter

Shared Rules
- No nested subagents
- No ownership crossing without lead reassignment
- Report blockers with exact file paths and failing commands/tests
- Retry loop count for this slice: 0
```

## Required Handoffs

### Researcher -> Architect

Pass:

- approved task text
- relevant file map
- current behavior summary
- current ownership by layer
- invariants and regression surface
- affected tests and contracts
- open unknowns only

Do not pass design suggestions from researcher because researcher must not produce them.

### Architect -> Lead

Pass:

- whether the planned change fits the intended layers
- whether dual-coder execution is safe or unsafe
- exact ownership boundaries that are acceptable
- any files or cross-layer joins that force serialization
- blocking structural risks that must be resolved before coding

### Lead -> Coders

Pass separately to each coder:

- exact owned files or layer responsibilities
- shared invariants both coders must preserve
- boundaries that must not be crossed into the other coder's ownership
- focused tests or commands expected for that owned slice

### Coders -> Lead

Pass:

- task objective
- changed files
- tests added or updated
- focused verification run by coder
- assumptions made during implementation
- any deviations from the plan and why

### Lead -> Reviewer + Tester

Pass:

- integrated change scope after both coder slices land
- merged changed-file list
- tests added or updated across both coder slices
- focused verification already run by each coder
- invariants and regressions that need explicit pressure

### Reviewer + Tester -> Coders on failure

Pass:

- exact blocker
- why it blocks progress
- exact file path or test file where the issue was observed
- failing command, failing test, or reproduction note when available
- ownership hint so the lead agent can route the fix back to the correct coder

### Reviewer + Tester -> Security

Pass only when both returned explicit `PASSED`.

Pass:

- validated scope
- residual risks worth checking in security review
- any areas that were close calls but not blocking in reviewer or tester

Do not re-open unrelated areas.

If reviewer or tester discovers a new structural concern that the pre-implementation architect gate did not cover, route that concern back through `lead_orchestrator` and re-open `architect` explicitly instead of silently inserting a second architecture pass.

### Security -> Explorer

Pass:

- whether security is acceptable
- any must-fix security blockers
- any boundaries explorer should watch when interpreting verification failures

### Explorer -> Lead

Pass:

- exact commands run
- pass/fail per command
- whether verification evidence is `Ready for Lead Review` or `Blocked`
- exact blockers, if any

## Gate Criteria

The lead agent should treat these as minimum pass conditions:

- `researcher`: current behavior, boundaries, impact surface, and unknowns are concrete
- `architect`: ownership split and layering fit are validated before coding starts
- `coder` (both instances): owned task slices implemented with tests and focused verification evidence, without nested delegation
- `reviewer`: explicit `PASSED` and no unresolved correctness or plan-alignment blockers
- `tester`: explicit `PASSED` and target behavior plus immediate regression surface validated
- `security`: no unresolved security blockers in changed surface
- `explorer`: required Docker verification run and reported with exact pass/fail status
- `lead_orchestrator`: final completion decision made from the full gate evidence

## Failure Escalation

If a gate fails:

- name the exact blocker
- send the work back only to the role that can resolve it
- when reviewer or tester fails, send the fix packet back to the coders with exact file references and concrete evidence
- after 2 failed coder retry loops on the same slice, stop the loop and escalate to a human checkpoint through `lead_orchestrator`
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
rtk docker compose exec -T php sh -lc 'cd /var/www/html && mkdir -p build'
rtk docker compose exec -T php sh -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpcs'
rtk docker compose exec -T php sh -lc 'cd /var/www/html && php vendor/bin/psalm --show-info=false --report=build/psalm-quality-report.json'
rtk docker compose exec -T php sh -lc 'cd /var/www/html && php bin/console lint:yaml -v --ansi --env=test config'
rtk docker compose exec -T php sh -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console cache:clear --env=test'
rtk docker compose exec -T php sh -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists'
rtk docker compose exec -T php sh -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction'
rtk docker compose exec -T php sh -lc 'cd /var/www/html && XDEBUG_MODE=coverage php -d memory_limit=-1 vendor/bin/phpunit --coverage-text --exclude-group ignore --coverage-clover build/phpunit.coverage.xml --coverage-cobertura build/phpunit.coverage.cobertura.xml --log-junit build/phpunit.xml'
```

## Exit Criteria

Do not claim the task is done until:

- both coders completed their scoped implementation without spawning child agents
- or the lead agent explicitly chose a single-coder path because ownership could not be split safely
- reviewer returned `PASSED` or all reviewer findings were fixed
- tester returned `PASSED` or all tester findings were fixed
- security found no unresolved blockers
- architect approved the pre-implementation ownership and structural fit, or forced a serialized path
- explorer passed the required local verification or clearly reported the blocking failures
- lead_orchestrator issued the final completion decision
- the final report states what was skipped, if anything, and why

## Tiny Work Shortcut

For tiny tasks, the lead agent may compress the pipeline:

- `researcher -> coder -> reviewer -> explorer`

Only skip `architect`, the second coder, `tester`, or `security` when the task clearly does not justify them, and state the reason.
