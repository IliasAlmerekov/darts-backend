# BE-704 RFC for precomputed/cached player stats

- Generated at: 2026-03-10 13:52:54 UTC
- Scope: design-only backup plan for `GET /api/players/stats`
- Status: RFC only, no implementation approved
- Depends on: `BE-701`, `BE-702`, `BE-703`

## Current baseline after BE-703

What is true now:

- live aggregation was already improved with:
  - `EXPLAIN` analysis in `BE-701`
  - selective indexes in `BE-702`
  - SQL simplification in `BE-703`
- on the local production-like benchmark dataset from `BE-703`:
  - old median wall-clock was about `173.62 ms`
  - new median wall-clock is about `91.62 ms`
  - `EXPLAIN ANALYZE` improved from about `236 ms` to about `153 ms`

Conclusion:

- live aggregation is currently still viable
- a precomputed path should remain a reserve option, not the default next step

## Decision

Do not implement precomputed/cached stats now.

Implement only if production or production-like measurements show that the current live query is no longer acceptable after the BE-702 and BE-703 optimizations.

## When to switch

Move from live aggregation to a precomputed/cached design only when at least one of these is true on representative traffic or a production-like dataset:

1. `/api/players/stats` violates its agreed SLO for at least 7 consecutive days.
2. Database time for the stats query remains materially high after BE-703, for example:
   - query `p95 > 150 ms`, or
   - endpoint `p95 > 250 ms`
3. Query cost grows roughly linearly with data and the growth trend shows that the next expected volume step will exceed the latency budget.
4. The endpoint becomes a visible database hotspot:
   - high call frequency
   - repeated expensive pagination/sorting requests
   - measurable contention with more important write flows
5. Additional live-query optimizations are exhausted or become too invasive for the domain model.

Do not switch based on intuition alone.

Required evidence before approving implementation:

- real endpoint latency distribution
- real query latency distribution
- current row counts for `game`, `round`, `round_throws`
- `EXPLAIN ANALYZE` on production-like data
- confirmation that BE-703 SQL is already deployed and measured

## Recommended approach if switch is needed

Preferred option:

- precomputed table with asynchronous refresh

Recommended table shape:

- one row per player
- fields needed by `/api/players/stats` only:
  - `player_id`
  - `username_snapshot`
  - `games_played`
  - `rounds_finished`
  - `total_value`
  - `score_average`
  - `updated_at`
  - optionally `source_version` or `rebuild_token`

Why this option:

- predictable read latency
- simple sorting/pagination by indexed columns
- avoids expensive runtime aggregation on `round_throws`
- easier to reason about than multi-layer application cache invalidation

Why not use a pure cache first:

- cache invalidation for player stats is tied to game finish/reopen/throw mutation flows
- cache-only designs usually hide inconsistency rather than model it
- hot pages with different sort and pagination combinations fragment cache keys quickly

Why not reuse current `PlayerStats` entity directly:

- current entity fields do not match the endpoint contract closely enough
- it looks like a legacy/generated placeholder rather than an owned read model for `/api/players/stats`
- if this path is implemented, the table should be treated as an explicit read model with clear ownership and refresh semantics

## Refresh model

Recommended refresh strategy:

- asynchronous incremental updates plus periodic full rebuild

Incremental triggers:

- game finished
- game reopened
- throw deleted or corrected in a finished game
- guest/player identity changes that affect displayed name or guest filtering

Refresh granularity:

- recompute only affected players when possible
- keep a full rebuild command for backfill, drift correction, and incident recovery

Consistency expectation:

- eventual consistency is acceptable only if product explicitly accepts it
- target freshness should be documented, for example:
  - normal mode: less than 1 minute old
  - recovery mode: temporarily stale but clearly bounded

## Read path design

If implemented, the endpoint should read from the precomputed table only through a separate repository or service.

Do not hide the switch inside the current live repository method.

Preferred structure:

- `LivePlayerStatsRepository` or current live path remains available
- `PrecomputedPlayerStatsRepository` reads the read model table
- one orchestration service chooses the strategy behind a feature flag or config switch

This keeps:

- rollback simple
- A/B measurement possible
- correctness comparisons easy during rollout

## Rollout plan

If implementation is approved later, use this order:

1. Create dedicated read-model table and migration.
2. Add one-off rebuild command.
3. Add background updater for affected players.
4. Run dual-write or dual-refresh without serving reads from it.
5. Compare live vs precomputed results on the same requests.
6. Enable read switch behind feature flag.
7. Keep live fallback until result parity and latency gains are confirmed.

## Risks

Main risks of precomputed stats:

- stale data after game mutations
- hidden drift between source-of-truth tables and read model
- operational complexity around rebuilds and backfills
- more code in finish/reopen/update flows
- harder debugging if live and precomputed paths diverge

Because of those risks, precomputed stats should be treated as an optimization with operational cost, not as a free win.

## Minimal approval checklist

Precomputed stats may be implemented only if all items below are true:

- current live path after BE-703 is measured and still too slow
- product accepts eventual consistency or bounded staleness
- owner agrees on freshness target
- rebuild strategy is specified
- rollback to live aggregation is specified
- result parity validation plan exists

## Recommendation

Current recommendation:

- keep live aggregation as the default path
- continue measuring after BE-703 in production-like conditions
- approve precomputed stats only if the measured trigger conditions above are reached

This RFC intentionally does not introduce any implementation changes.
