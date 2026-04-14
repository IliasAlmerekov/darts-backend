# Backend Claude Orchestration

This file defines the project-local Claude agent workflow for this Symfony backend.

## Source Of Truth

Before non-trivial work, read:

1. `AGENTS.md`
2. `app/AGENTS.md`
3. `.claude/CLAUDE.md`
4. `.codex/context/current-focus.md`
5. `.codex/context/project-map.md`
6. `.codex/context/domain-map.md`
7. `.codex/context/decisions.md`
8. `.codex/context/engineering-principles.md`
9. relevant local `.claude/commands/*` and `.claude/skills/*`

If instructions conflict, prefer repository `AGENTS.md`, then this file.

## Persistent Context

Use `.codex/context/*` as the first-pass memory layer for new sessions, even in
Claude sessions.

- `current-focus.md`: current branch and active work context
- `project-map.md`: structural map of the repository
- `domain-map.md`: main business flows and ownership
- `decisions.md`: stable technical decisions
- `engineering-principles.md`: project-specific design and abstraction heuristics
- `backlog.md`: pending work and follow-ups
- `changelog.md`: append-only recent change summary

These files reduce rediscovery cost. They do not replace the codebase. If they
become stale, correct them.

## Workflow Sync

`.codex` is the primary authored workflow layer.

- Read shared context from `.codex/context`
- Treat `.claude` workflow files as a mirror of `.codex`
- When workflow files change, mirror the relevant updates from `.codex` into `.claude`

## Execution Policy

- All shell commands must go through `rtk`
- Never use raw shell commands
- Work in the current branch
- Do not require, suggest, or create `git worktree`

## Lead-Orchestrated Flow

Use `lead_orchestrator` as the entry point for non-trivial tickets.

Default closed loop:

1. `lead_orchestrator`
2. `researcher`
3. `coder`
4. `reviewer`
5. `tester`
6. `security`
7. `architect`
8. `explorer`

`lead_orchestrator` owns routing, scope control, and completion criteria.

## Execution Manifesto

All non-trivial work in this repository should preserve the reasoning established
during brainstorming and planning.

Execution must not degrade approved work into:

- generic file-edit tasks without behavioral context
- symptom-fixes that leave the root cause in place
- happy-path-only validation
- success claims that skip the final Docker verification gate

Every execution slice should preserve:

- task mode
- approved scope boundaries
- invariants that must remain true
- architecture ownership by layer
- regression surface
- compatibility, rollback, and failure-mode constraints when relevant

## Senior Reasoning Standard

Think like a senior backend engineer at execution time, not only during design.

That means:

- use the smallest change at the correct layer
- prefer root-cause removal over defensive symptom patches
- treat passing focused tests as evidence, not proof of full readiness
- keep facts, assumptions, and unknowns separate
- escalate plan gaps instead of inventing architecture during coding

If the code contradicts the approved plan in a material way, pause execution and
return to planning rather than improvising a larger design change.

## Hard Rules

- No coding before design approval for `normal` and `complex` work
- No coding before a plan exists
- TDD is mandatory for `coder`
- `researcher` is context-only and must not propose solutions
- `explorer` owns the final local CI-equivalent verdict

## Task Packet Contract

When `lead_orchestrator` dispatches a non-trivial task slice, the handoff should
carry:

- exact task name
- task mode
- approved scope boundaries
- invariants to preserve
- current regression surface
- expected files or layers in play
- focused commands already run
- open findings, assumptions, or unknowns still in play

Do not hand off only a filename list without the behavioral context.

## Gate Semantics

Each agent is a quality gate with a different burden of proof:

- `researcher`: current behavior, boundaries, impact surface, and unknowns are concrete
- `coder`: implementation plus tests and focused verification evidence
- `reviewer`: correctness, plan alignment, and no unresolved wrong-layer or regression blockers
- `tester`: changed behavior and immediate regression surface validated
- `security`: no unresolved security blockers in changed surface
- `architect`: no unresolved structural, contract, migration, or abstraction blockers
- `explorer`: required Docker verification run and reported with exact pass/fail status

Do not bypass a failing gate to keep momentum.

## Bug Discipline

For bug and regression work:

- require root-cause reasoning, not only symptom description
- require tests that prove the broken path existed or was covered
- require implementation at the correct ownership layer
- require validation that adjacent scenarios from the same defect class are not left exposed

Do not accept fixes that only add guards, null checks, or catch blocks unless the
approved design already proved that is the correct root-cause fix.

## Completion Evidence

Before closing work, `lead_orchestrator` should be able to summarize:

- what exact slice was implemented
- what invariants were preserved
- what each gate proved
- what commands were run
- what was skipped, if anything, and why
- whether `explorer` returned `Ready` or `Blocked`

## Context Maintenance

After meaningful work:

- always update `.codex/context/changelog.md`
- update `.codex/context/current-focus.md` if active priorities or likely next steps changed
- update `.codex/context/backlog.md` if backlog status changed
- update `.codex/context/decisions.md` only for real technical decisions
- update `.codex/context/project-map.md` or `.codex/context/domain-map.md` only when structure or ownership changed

## Completion Gate

A task is not done until:

- implementation matches the approved scope
- reviewer issues are resolved
- tester validation is complete
- security found no unresolved blockers
- architect found no unresolved structural blockers
- explorer ran required verification or reported exact blockers
