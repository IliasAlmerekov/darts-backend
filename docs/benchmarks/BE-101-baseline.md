# BE-101 baseline for game endpoints

- Generated at: 2026-03-10 09:35:43 UTC
- Environment: PHPUnit functional test (`APP_ENV=test`) with MySQL in Docker and Symfony profiler enabled.
- Samples per row: 3 identical runs; latency is wall-clock median measured around the in-process HTTP request.

## Baseline table

| Endpoint | Scenario | SQL count | Latency median (ms) | Latency range (ms) | SQL time median (ms) | App duration median (ms) |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `GET /api/game/{id}` | `small_started` | 17 | 30.72 | 29.56–110.64 | 0.01 | 22.28 |
| `GET /api/game/{id}` | `medium_started` | 36 | 50.55 | 49.60–50.96 | 0.01 | 42.27 |
| `GET /api/game/{id}` | `long_started_with_history` | 97 | 127.19 | 123.17–141.04 | 0.04 | 116.39 |
| `PATCH /api/game/{id}/settings` | `lobby_settings_update` | 17 | 32.91 | 31.95–50.12 | 0.01 | 25.90 |
| `DELETE /api/game/{id}/throw` | `started_undo_last_throw` | 93 | 117.16 | 100.84–142.71 | 0.04 | 105.26 |

## Scenarios

- `small_started`: 2 players, started game, current round only, no persisted round history.
- `medium_started`: 3 players, started game, 5 finished rounds plus partial current round.
- `long_started_with_history`: 4 players, started game, 18 finished rounds plus partial current round.
- `lobby_settings_update`: 3 players, lobby game used for PATCH settings baseline.
- `started_undo_last_throw`: 4 players, started game with 12 finished rounds and a partially played current round used for DELETE throw baseline.

## How to rerun

From the repository root, run the benchmark in the PHP container after resetting the test schema:

- `docker compose exec -T php sh -lc 'cd /var/www/html && php bin/console doctrine:schema:drop --env=test --full-database --force || true && php bin/console doctrine:database:create --env=test --if-not-exists && php bin/console doctrine:schema:create --env=test && vendor/bin/phpunit --filter GameEndpointsBaselineTest --testdox'`

The generated runtime report is also written to `app/var/benchmarks/be-101-game-endpoints-baseline.md`.
