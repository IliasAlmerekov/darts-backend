---
description: "Shape a backend feature for this repository by running local brainstorming first, then handing off to planning_feature, without using git worktrees."
---

Use the local repository workflow for feature shaping.

## Required Flow

1. Use the local `.codex/skills/brainstorming` skill first.
2. Do not implement code until the design is presented and approved.
3. If the approved design should be persisted, save it to `docs/specs/YYYY-MM-DD-<topic>.md`.
4. Do not commit the spec file as part of brainstorming.
5. After design approval, hand off to `.codex/commands/planning_feature.md`.
6. The planning flow uses local `writing-plans` and then local `subagent-development`.

## Repository Rules

- Work in the current branch.
- Do not require, suggest, or create `git worktree`.
- Use this repository's `AGENTS.md` and `app/AGENTS.md` as the operating policy.
- Treat `app/composer.json`, `.gitlab-ci.yml`, and the current code as source of truth.
- All shell commands must go through `rtk`.

## Planning Expectations

- Plans must be specific to this Symfony backend.
- Plans must identify affected controllers, DTOs, services, repositories, serializers, tests, and migrations when relevant.
- Plans must include root-level Docker verification commands through `rtk docker compose ...`.

## If The Request Is Too Small

If the change is truly tiny, still use the local `brainstorming` skill, but allow a short inline design and a short inline plan instead of writing files unless the user wants them persisted.
