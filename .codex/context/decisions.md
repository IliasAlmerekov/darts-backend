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

### D-010: In `subagent-development`, only `lead_orchestrator` may delegate

- Status: active
- Why: execution agents such as `coder` are already subagents and must remain leaf gates instead of spawning nested agent trees
- Guardrail: route blockers back to `lead_orchestrator` rather than delegating further

### D-011: Non-tiny implementation may use two coders only after architect approves a disjoint ownership split

- Status: active
- Why: parallel implementation is allowed only when ownership is non-overlapping, architect confirms the split is structurally safe, and the lead agent keeps the shared invariants coherent
- Guardrail: if ownership would overlap, serialize or rebalance instead of letting coders edit the same file concurrently

### D-012: `explorer` is the integration verifier, while `lead_orchestrator` owns workflow closure

- Status: active
- Why: integration verification evidence and final workflow judgment are different responsibilities
- Guardrail: `explorer` reports verification readiness or blockers; only `lead_orchestrator` may close the task

### D-013: Reviewer/tester-to-coder retries are capped

- Status: active
- Why: avoid unbounded loops on stylistic or ambiguous disagreements
- Guardrail: after 2 failed retry loops on the same slice, escalate to a human checkpoint through `lead_orchestrator`
