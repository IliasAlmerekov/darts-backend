# BE-702 player stats index verification

- Generated at: 2026-03-10 13:52:54 UTC
- Scope: validate index additions for `GET /api/players/stats` / `RoundThrowsRepository::getPlayerStatistics()`
- Environment: local Docker stack, MySQL 8.0, benchmark data loaded into `testphp_db_test`

## Applied indexes

Added:

- `game(status, game_id)` as `idx_game_status_game_id`
- `round(finished_at, round_id)` as `idx_round_finished_at_round_id`
- `user(is_guest, id)` as `idx_user_is_guest_id`

Not added after verification:

- a covering index on `round_throws(game_id, player_id, round_id, is_bust, value)`

Reason:

- it made MySQL switch to a full covering index scan on `round_throws`
- on the benchmark dataset that regressed the main aggregate query instead of improving it

## Benchmark dataset

Synthetic production-like dataset used for comparison:

- `user`: 240
  - 200 non-guest players
  - 40 guest players
- `game`: 1000
  - 220 `finished`
  - 780 `started`
- `round`: 11100
  - 5640 with `finished_at IS NOT NULL`
- `round_throws`: 133200

The important property is selectivity:

- only part of the total game volume is `finished`
- stats query must skip a large amount of non-finished game data

## Before indexes

Main stats query `EXPLAIN ANALYZE` before index changes:

- driving step on `game`: `Table scan on g1_`
- then nested lookups into `round_throws` by `game_id`
- total query time in `EXPLAIN ANALYZE`: about `211 ms`

Relevant fragment:

```text
-> Filter: (g1_.status = 'finished')  (actual time=0.149..0.516 rows=220 loops=1)
    -> Table scan on g1_  (actual time=0.145..0.388 rows=1000 loops=1)
```

## After indexes

Main stats query `EXPLAIN ANALYZE` after final index set:

- MySQL switched from a table scan on `game` to a covering index lookup on `idx_game_status_game_id`
- total query time in `EXPLAIN ANALYZE`: about `182 ms`

Relevant fragment:

```text
-> Covering index lookup on g1_ using idx_game_status_game_id (status='finished')  (actual time=0.0277..0.155 rows=220 loops=1)
```

Measured delta for the main aggregate query:

- before: `211 ms`
- after: `182 ms`
- improvement: about `13.7%`

## Interpretation

What improved:

- filtering on finished games is now index-backed instead of scanning all rows in `game`
- this reduces the cost of the driving step and the downstream fan-out into `round_throws`

What did not become the primary win:

- `GROUP BY`
- `COUNT(DISTINCT game_id)`
- `COUNT(DISTINCT round_id)`
- final sort by computed average

Those phases still dominate the query after filtering, so the endpoint is faster but not transformed. The index work removes avoidable filtering cost; it does not eliminate aggregation cost.

## Rejected candidate

Tested and rejected:

- `round_throws(game_id, player_id, round_id, is_bust, value)`

Observed plan after adding it:

- `Covering index scan on r2_ using idx_rt_game_player_round_bust_value`
- full pass over all `133200` throw rows before filtering by finished games

That was worse than letting MySQL start from finished games and then probe `round_throws` by the existing foreign-key index on `game_id`.

## Migration safety

Safety properties of the final migration:

- only additive secondary indexes are created
- no data rewrite is performed
- `down()` drops the added indexes by name
- foreign keys and existing indexes are untouched

## How to rerun

From the repository root:

1. Reset and migrate the test database.
2. Seed the benchmark dataset.
3. Run `EXPLAIN ANALYZE` for the stats query before/after schema changes on the same seed shape.

The benchmark commands used during this task were run ad hoc inside Docker and can be reconstructed from the shell history in this workspace.
