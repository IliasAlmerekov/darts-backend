# BE-703 player stats SQL simplification

- Generated at: 2026-03-10 13:52:54 UTC
- Scope: simplify `RoundThrowsRepository::getPlayerStatistics()` without changing results
- Environment: local Docker stack, MySQL 8.0, benchmark data loaded into `testphp_db_test`

## Query change

Previous shape:

- aggregate directly on throw-level rows
- `COUNT(DISTINCT game_id)`
- `COUNT(DISTINCT round_id)`
- `SUM(...)` over the same throw-level dataset

New shape:

- first aggregate throws into one row per `player + game + round`
- outer query aggregates these round-level rows per player
- `roundsFinished` becomes `COUNT(*)` in the outer query
- only `gamesPlayed` still needs `COUNT(DISTINCT gameId)`

This reduces the row volume that reaches the final aggregation and sort stages.

## Result equivalence

Comparison on the benchmark dataset:

- old result row count: `200`
- new result row count: `200`
- full normalized result sets: identical

Compared fields:

- `playerId`
- `username`
- `gamesPlayed`
- `totalValue`
- `roundsFinished`
- `scoreAverage`

## Benchmark dataset

- `user`: 240
  - 200 non-guest players
  - 40 guest players
- `game`: 1000
  - 220 `finished`
  - 780 `started`
- `round`: 11100
- `round_throws`: 133200

## EXPLAIN ANALYZE

Old query:

- total time in `EXPLAIN ANALYZE`: about `236 ms`
- grouped throw-level rows after filtering: `37620`

New query:

- total time in `EXPLAIN ANALYZE`: about `153 ms`
- materialized round-level rows before final grouping: `12540`

Interpretation:

- the new query still pays for the initial join/filter phase
- the main win comes from shrinking the data before the final group/sort phase

## Wall-clock timing

7 warm runs, same SQL parameters (`ORDER BY scoreAverage DESC LIMIT 20`):

- old median: `173.62 ms`
- new median: `91.62 ms`

Measured improvement:

- about `47.2%` faster by median wall-clock time

## Acceptance status

- new SQL gives the same results: yes
- `EXPLAIN` is better than baseline: yes

