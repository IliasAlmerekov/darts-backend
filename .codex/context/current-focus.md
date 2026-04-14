# Current Focus

Last updated: 2026-04-14

## Current State

- The local `.codex` workflow is now active and has been exercised on a real Symfony code change
- The most recent application change optimized the deprecated full undo endpoint path without changing its HTTP contract
- The project now has local skills for brainstorming, planning, and subagent execution, plus one completed end-to-end dry run through the full gate pipeline

## Important Immediate Context

- Deprecated `DELETE /api/game/{id}/throw` now does less redundant backend work:
  - removed the dead undo repository lookup in `GameThrowService`
  - collapsed full snapshot throw batching in `GameService` to one ordered `findRoundHistoryForGame()` pass
- The endpoint contract, undo semantics, and `GameResponseDto` behavior were kept unchanged
- Repository verification guidance is now aligned with the live local container runtime:
  - use `sh -lc` for `rtk docker compose exec -T php ...`
  - set `XDEBUG_MODE=coverage` for the coverage PHPUnit command
- Historical artifacts still exist under `docs/superpowers/specs` and `docs/superpowers/plans`
- Those historical artifacts may still contain stale references, including older stack wording such as Symfony `7.4`
- The local workflow under `.codex` should be treated as the active project workflow

## Known Historical Work Item

- `Game Start Micro-Optimizations`
  - historical spec: `docs/superpowers/specs/2026-04-14-game-start-micro-optimizations-design.md`
  - historical plan: `docs/superpowers/plans/2026-04-14-game-start-micro-optimizations.md`
  - note: useful as context, but re-verify stack assumptions and current code before executing it

## Likely Next Steps

- If follow-up hardening is requested, add a permanent `GameServiceTest` case that explicitly locks the repository-order dependency behind mixed-player/interleaved history rows
- Decide whether to commit the deprecated undo optimization, verification policy fix, and related spec/plan artifacts
- Decide how to handle the historical `docs/superpowers/*` documents

## Session Reminder

- Start with this file, then `project-map.md`, `domain-map.md`, and `decisions.md`
- Trust the repository over this context layer if anything conflicts
