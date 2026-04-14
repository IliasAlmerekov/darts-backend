---
description: "Execute an approved implementation plan in this Symfony backend through the local multi-agent pipeline led by lead_orchestrator, in the current branch and without git worktrees."
---

Use the local repository workflow for plan execution.

## Expected Input

- An approved plan, usually at `docs/plans/YYYY-MM-DD-<topic>.md`
- Approved requirements source:
  - spec file for `normal` or `complex` work
  - explicit approved inline design for `tiny` work

## Required Flow

1. `lead_orchestrator` owns the execution flow.
2. Use the local `.codex/skills/subagent-development` skill.
3. Default execution pipeline:
   - `researcher`
   - `coder`
   - `reviewer`
   - `tester`
   - `security`
   - `architect`
   - `explorer`
4. `researcher` is context-only and must not propose changes.
5. `coder` follows TDD.
6. `explorer` owns the final local CI-equivalent verdict.
7. Execution must preserve approved invariants, architecture boundaries, regression surface, and root-cause expectations for bug work.

## Dispatch Checklist

Before `lead_orchestrator` dispatches any non-trivial slice to another agent,
the task packet should include:

- exact task name
- task mode
- approved scope boundaries
- invariants to preserve
- current regression surface
- expected files or layers in play
- focused commands already run
- open findings, assumptions, or unknowns still in play

Do not dispatch only a file list or a vague "implement this" instruction.

## Repository Rules

- Work in the current branch.
- Do not require, suggest, or create `git worktree`.
- Use this repository's `AGENTS.md`, `app/AGENTS.md`, and `.codex/AGENTS.md` as the operating policy.
- Treat `app/composer.json`, `.gitlab-ci.yml`, and the current code as source of truth.
- All shell commands must go through `rtk`.

## Verification Expectations

- Focused task-level checks may run during `coder` and `tester`.
- Final required verification is owned by `explorer`.
- When code changed, `explorer` should run the required Docker-based root-level verification batch.
- A passing focused test or reviewer approval is not a final success signal.

## Git Rule

- Do not create commits just because the plan entered execution.
- Commits happen only when the user explicitly wants them or a later agreed workflow handles them.
