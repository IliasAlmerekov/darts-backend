# Decisions

Last updated: 2026-04-14

## Active Decisions

### D-001: Use local `.codex` and `.claude` skill workflows instead of `superpowers`

- Status: active
- Why: keep project-local skills, agents, and policies under repository control
- Implementation: `.codex` is the primary authored workflow layer, while `.claude` mirrors it for Claude runtime use

### D-002: Work only in the current branch

- Status: active
- Why: this repository workflow explicitly avoids `git worktree`
- Implication: risk is managed through tighter scope, TDD, quality gates, and explicit verification

### D-003: All shell execution must go through `rtk`

- Status: active
- Why: repository policy requires it and keeps command execution consistent

### D-004: Non-trivial work follows `brainstorming-feature -> planning-feature -> subagent-development`

- Status: active
- Why: force design clarity, explicit planning, and gate-based execution

### D-005: `researcher` is context-only

- Status: active
- Why: keep discovery separate from solutioning and reduce premature design bias

### D-006: TDD is mandatory for implementation work

- Status: active
- Why: execution must prove behavior changes and bug fixes instead of relying on optimistic code edits

### D-007: Spec and plan artifacts are not committed at brainstorming or planning time

- Status: active
- Why: design and planning artifacts should remain editable until later workflow or explicit user intent handles commits

### D-008: Persistent context lives in `.codex/context`

- Status: active
- Why: reduce rediscovery cost across sessions and lower context hallucination risk
- Guardrail: these files are secondary to the repository and must be corrected when stale

### D-009: Workflow definitions are authored in `.codex` and mirrored into `.claude`

- Status: active
- Why: avoid silent drift between Codex-side and Claude-side workflow behavior
- Guardrail: update `.codex` first, then mirror relevant changes into `.claude`
