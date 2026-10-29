# BE-102 baseline for game endpoints after batched DTO reads

- Generated at: 2026-03-10 09:54:40 UTC
- Change scope: `GameService::createGameDto()` rewritten to use batched reads for game players, current-round throws, latest throw per player, and full round history assembly in memory.
- Environment: PHPUnit functional test (`APP_ENV=test`) with MySQL in Docker and Symfony profiler enabled.
- Samples per row: 3 identical runs; latency is wall-clock median measured around the in-process HTTP request.
- Reference baseline: `app/tests/Benchmark/BE-101-baseline.md`

## Current table

| Endpoint | Scenario | SQL count | Latency median (ms) | Latency range (ms) | SQL time median (ms) | App duration median (ms) |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `GET /api/game/{id}` | `small_started` | 7 | 24.20 | 22.69–110.20 | 0.00 | 18.13 |
| `GET /api/game/{id}` | `medium_started` | 7 | 29.72 | 27.36–32.64 | 0.00 | 22.64 |
| `GET /api/game/{id}` | `long_started_with_history` | 7 | 50.63 | 49.20–50.85 | 0.00 | 43.21 |
| `PATCH /api/game/{id}/settings` | `lobby_settings_update` | 8 | 26.96 | 25.48–42.90 | 0.00 | 20.08 |
| `DELETE /api/game/{id}/throw` | `started_undo_last_throw` | 23 | 62.39 | 61.59–89.46 | 0.01 | 54.58 |

## Delta vs BE-101

| Endpoint | Scenario | SQL count before | SQL count after | SQL delta | SQL reduction |
| --- | --- | ---: | ---: | ---: | ---: |
| `GET /api/game/{id}` | `small_started` | 17 | 7 | -10 | -58.8% |
| `GET /api/game/{id}` | `medium_started` | 36 | 7 | -29 | -80.6% |
| `GET /api/game/{id}` | `long_started_with_history` | 97 | 7 | -90 | -92.8% |
| `PATCH /api/game/{id}/settings` | `lobby_settings_update` | 17 | 8 | -9 | -52.9% |
| `DELETE /api/game/{id}/throw` | `started_undo_last_throw` | 93 | 23 | -70 | -75.3% |

## Scenarios

- `small_started`: 2 players, started game, current round only, no persisted round history.
- `medium_started`: 3 players, started game, 5 finished rounds plus partial current round.
- `long_started_with_history`: 4 players, started game, 18 finished rounds plus partial current round.
- `lobby_settings_update`: 3 players, lobby game used for PATCH settings baseline.
- `started_undo_last_throw`: 4 players, started game with 12 finished rounds and a partially played current round used for DELETE throw baseline.

## What BE-102 changed

- Removed per-player and per-round repository reads during `GameResponseDto` assembly.
- Loaded game players eagerly with player relations in one query.
- Loaded all throws for the game in one ordered query.
- Built maps in memory for:
  - current round throws by player
  - latest throw by player
  - round history by player and round
- Preserved `GameResponseDto` contract and existing behavior for:
  - active player
  - current throw count
  - bust status
  - round history

## How to rerun

From the repository root, run the benchmark in the PHP container after resetting the test schema:

- `docker compose exec -T php sh -lc 'cd /var/www/html && php bin/console doctrine:schema:drop --env=test --full-database --force || true && php bin/console doctrine:database:create --env=test --if-not-exists && php bin/console doctrine:schema:create --env=test && vendor/bin/phpunit --filter GameEndpointsBaselineTest --testdox'`

The generated runtime report is also written to `app/var/benchmarks/be-101-game-endpoints-baseline.md`.

## Suggested handoff for the next context window

- Treat this file as the new post-BE-102 starting point.
- Use `app/tests/Benchmark/BE-101-baseline.md` as the pre-optimization reference.
- If the next task targets `GET /api/game/{id}`, note that DTO assembly is no longer the dominant source of query growth; investigate remaining fixed query cost and non-DTO flows next.
