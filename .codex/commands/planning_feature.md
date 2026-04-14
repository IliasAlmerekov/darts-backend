---
description: "Create an implementation plan for this Symfony backend from an approved design, then prepare execution through the local subagent-development workflow in the current branch."
---

Use the local repository workflow for feature planning.

## Expected Input

- Prefer an approved spec created by the local `.codex/skills/brainstorming` skill.
- For `normal` and `complex` brainstorming outcomes, expect a spec file at `docs/specs/YYYY-MM-DD-<topic>.md`.
- For `tiny` outcomes, a persisted spec file is optional if the approved inline design is explicit enough.

## Required Flow

1. Confirm the approved requirements source.
2. Use the local `.codex/skills/writing-plans` skill.
3. Save the plan to `docs/plans/YYYY-MM-DD-<topic>.md` unless the user explicitly wants it inline.
4. Do not commit the plan file as part of planning.
5. After plan approval, hand off to `.codex/commands/subagent-development.md`.

## Planning Handoff Checklist

Before handing execution to `lead_orchestrator`, make sure the approved plan or
handoff summary states:

- exact task or first execution slice
- task mode
- approved scope boundaries
- invariants to preserve
- current regression surface
- expected files or layers in play
- root-cause expectation for bug work
- focused risks, rollback notes, or compatibility constraints that execution must keep

## Repository Rules

- Work in the current branch.
- Do not require, suggest, or create `git worktree`.
- Use this repository's `AGENTS.md` and `app/AGENTS.md` as the operating policy.
- Treat `app/composer.json`, `.gitlab-ci.yml`, and the current code as source of truth.
- All shell commands must go through `rtk`.

## Execution Model

Use the fixed local agent pipeline unless the task is tiny:

0. `lead_orchestrator`
1. `researcher`
2. `coder`
3. `reviewer`
4. `tester`
5. `security`
6. `architect`
7. `explorer`

Researcher is context-only and must not propose changes.

Coder follows TDD.

Explorer owns the final local CI-equivalent verdict.
