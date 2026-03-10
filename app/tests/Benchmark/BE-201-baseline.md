# BE-201 baseline for lightweight game settings endpoint

- Generated at: 2026-03-10 10:47:37 UTC
- Change scope: introduced `GET /api/game/{id}/settings` as a lightweight alternative to the full game state endpoint.
- Environment: PHPUnit functional test (`APP_ENV=test`) with MySQL in Docker and Symfony profiler enabled.
- Samples per row: 3 identical runs; latency is wall-clock median measured around the in-process HTTP request.
- Comparison model: each scenario is measured once for `GET /api/game/{id}/settings` and once for `GET /api/game/{id}` under the same fixture shape.

## Current table

| Endpoint | Scenario | SQL count | Payload bytes | Latency median (ms) | Latency range (ms) | SQL time median (ms) | App duration median (ms) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `GET /api/game/{id}/settings` | `lobby_three_players` | 3 | 82 | 19.61 | 18.99–62.09 | 0.00 | 13.12 |
| `GET /api/game/{id}` | `lobby_three_players_full_state` | 9 | 737 | 31.49 | 28.30–52.05 | 0.01 | 22.80 |
| `GET /api/game/{id}/settings` | `started_medium` | 3 | 84 | 20.94 | 19.68–24.75 | 0.00 | 13.44 |
| `GET /api/game/{id}` | `started_medium_full_state` | 9 | 4237 | 35.82 | 34.03–37.92 | 0.00 | 25.21 |
| `GET /api/game/{id}/settings` | `started_long_history` | 3 | 85 | 27.34 | 22.08–29.55 | 0.00 | 18.73 |
| `GET /api/game/{id}` | `started_long_history_full_state` | 9 | 16474 | 51.77 | 51.76–55.97 | 0.01 | 44.18 |

## Delta vs full game state

| Scenario | Full-state SQL | Settings SQL | SQL delta | Full-state payload bytes | Settings payload bytes | Payload reduction |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `lobby_three_players` | 9 | 3 | -6 | 737 | 82 | 88.9% |
| `started_medium` | 9 | 3 | -6 | 4237 | 84 | 98.0% |
| `started_long_history` | 9 | 3 | -6 | 16474 | 85 | 99.5% |

## Scenarios

- `lobby_three_players`: 3 players, lobby game, no rounds played yet.
- `started_medium`: 3 players, started game, 5 finished rounds plus partial current round.
- `started_long_history`: 4 players, started game, 18 finished rounds plus partial current round.

## Notes

- The lightweight endpoint avoids `GameService::createGameDto()` and returns only settings-focused data.
- Payload size is measured as raw response body bytes from the functional response content.
- SQL count should remain flat across started scenarios because the lightweight endpoint reads only the game plus access-related data, not throw history.

## How to rerun

- `docker compose exec -T php sh -lc 'cd /var/www/html && php bin/console doctrine:schema:drop --env=test --full-database --force || true && php bin/console doctrine:database:create --env=test --if-not-exists && php bin/console doctrine:schema:create --env=test && XDEBUG_MODE=coverage vendor/bin/phpunit --filter GameSettingsReadBaselineTest --testdox'`

The generated runtime report is also written to `app/var/benchmarks/be-201-game-settings-baseline.md`.
