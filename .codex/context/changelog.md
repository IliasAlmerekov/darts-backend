# Changelog

This file is append-only. Keep entries short and technical.

## 2026-04-14

- Created local `.codex` workflow with project-specific skills, agents, and config
- Added `brainstorming-feature`, `planning-feature`, and `subagent-development` skills tailored to this Symfony backend
- Added local agent roles: `lead_orchestrator`, `researcher`, `coder`, `reviewer`, `tester`, `security`, `architect`, `explorer`
- Hardened brainstorming and planning to preserve task mode, invariants, root-cause reasoning, regression surface, and TDD expectations
- Hardened execution pipeline with gate semantics, handoff contracts, and final-verdict ownership in `explorer`
- Updated repository `AGENTS.md` to the actual stack and local workflow
- Added persistent context layer under `.codex/context`
- Added project-level `.claude/settings.json` so the mirrored Claude workflow also has local runtime settings
- Rewrote `symfonystrategy-pattern` into a narrow reference-first skill and mirrored it into `.claude`
- Removed `.claude/context` and made `.codex/context` the single persistent context source for both runtimes
- Restored repository root `AGENTS.md`, updated context decisions and project map for `.claude`, removed the noisy Claude Stop hook, and documented `.codex` as the primary workflow source
- Merged useful rules from `.github/CODE_QUALITY_GUIDE.md` into repository policies, fixed raw-command guidance in `app/AGENTS.md`, and removed the stale duplicate guide
- Added `.codex/context/engineering-principles.md` as a project-specific decision framework for abstraction, pattern fit, and wrong-layer fixes, and wired it into Codex and Claude read order
- Renamed local workflow entry skills to `brainstorming-feature` and `planning-feature` so the three-step flow is skill-only and matches the old command invocation names
- Removed mirrored `.codex/commands` and `.claude/commands` files and rewired workflow docs to point directly at skills
- Optimized deprecated full undo snapshot assembly by removing the dead undo repository lookup and deriving current-round/latest throw maps from one round-history query in `GameService`
- Aligned repository verification policy and mirrored `.codex`/`.claude` skill command examples with the actual PHP container by switching `bash -lc` to `sh -lc` and adding `XDEBUG_MODE=coverage` for the coverage PHPUnit command
- Removed dead `RoundThrowsRepository` contract methods left behind by the undo optimization so Psalm no longer fails on `PossiblyUnusedMethod`
- Hardened the `explorer` agent so gameplay-state changes touching `GameThrowService`, `GameService`, or `RoundThrowsRepository` must run the focused undo/full-state regression test batch before the final verdict
- Reworked `subagent-development` so non-tiny execution defaults to two coders with explicit disjoint ownership, a reviewer+tester joint pass/fail gate, and leaf-agent enforcement that forbids nested coder delegation
- Refined the workflow again so `architect` now runs before implementation, dual-coder execution is allowed only when ownership is explicitly safe, reviewer/tester retries are capped, `explorer` is an integration verifier rather than workflow owner, and `lead_orchestrator` owns final completion
- Synced root `AGENTS.md` with the architect-first / lead-final workflow and added a canonical ownership packet template so `lead_orchestrator` emits consistent coder A/B handoffs
- Added a filled example ownership packet to `subagent-development` so `lead_orchestrator` has both a template and a concrete dispatch sample
