# BE-259 player profile memory

- Generated at: 2026-11-05 UTC
- Scope: `GET /api/player/profile` reads a player's throws one at a time instead of loading them into arrays (#259).
- Before: `main` 6b6238f. After: the same commit plus the change in #259.

## Environment

- This measures a **full HTTP request**: login through `POST /api/login` with a session cookie, then the real
  route, firewall, controller and serializer.
- Server: PHP's built-in server with `APP_ENV=prod`, `APP_DEBUG=0` and `memory_limit = 64M`, in the dev image
  (`php:8.4.27-0-dev`, Xdebug loaded with `xdebug.mode=off`). It is **not** php-fpm behind nginx and not the runtime image.
- Database: MySQL 8.0 in Docker, a scratch database that only this run uses, migrated to the latest version.
- Data: synthetic games of 60 darts in 20 turns, one player and one opponent seat, half double out, half single
  out, the last dart a checkout. Production data was not checked.
- OPcache `on` uses the sizes in `app/docker/php.ini`, as the runtime image does. `off` compiles every file into
  request memory, so it is the worse case for the limit.
- Peak memory is `memory_get_peak_usage(true)`, the memory allocated from the system, which the limit compares against; it moves in coarse steps, often 2 MiB. Each row
  takes the highest peak and the median time of 3 requests after one warm-up request.

## Results under memory_limit = 64M

| Throws | OPcache | Before | Before peak (MiB) | Before median (ms) | After | After peak (MiB) | After median (ms) |
| ---: | --- | --- | ---: | ---: | --- | ---: | ---: |
| 30,000 | on | 200 | 46.0 | 165 | 200 | 6.0 | 144 |
| 60,000 | on | 500, memory exhausted | n/a | n/a | 200 | 6.0 | 253 |
| 240,000 | on | no response, process killed | n/a | n/a | 200 | 17.7 | 1694 |
| 30,000 | off | 200 | 62.0 | 232 | 200 | 20.0 | 213 |
| 60,000 | off | 500, memory exhausted | n/a | n/a | 200 | 22.0 | 337 |
| 240,000 | off | no response, process killed | n/a | n/a | 200 | 31.7 | 1722 |

The 500 logged `Allowed memory size of 67108864 bytes exhausted`. At 240,000 throws the process also ended with
`Hard memory limit of 134217728 bytes exhausted`, so the built-in server gave no response.

## Old code without the limit

The old code at `memory_limit = 2G`, to compare the larger histories:

| Throws | OPcache | Peak (MiB) | Median (ms) | Body bytes | Body SHA-256 (before and after) |
| ---: | --- | ---: | ---: | ---: | --- |
| 30,000 | on | 44.0 | 152 | 4697 | `f154ee796559e060` |
| 60,000 | on | 86.0 | 305 | 4706 | `9864cd21f32c8a04` |
| 240,000 | on | 340.0 | 1805 | 4710 | `0a8ffecc109a715b` |
| 30,000 | off | 62.0 | 221 | 4697 | `f154ee796559e060` |
| 60,000 | off | 102.0 | 356 | 4706 | `9864cd21f32c8a04` |
| 240,000 | off | 356.0 | 1880 | 4710 | `0a8ffecc109a715b` |

The response bodies of the old and the new code have the same hash at every size.

## Reading the results

- Memory falls from about 1.4 KiB per throw to about 60 bytes per throw. What is left most likely comes from the
  driver's buffered result (inferred, not measured on its own). That is the driver's default mode, which needs no code
  and lets other queries run on the same connection. `build()` also keeps a small entry per game, not per throw.
- By extrapolation, the limit is reached at about 900,000 throws (about 15,000 games of 60 darts) with OPcache on.
- Time barely changes and still grows with the history: about 1.7 s at 240,000 throws. This change does not address
  it; the `shortcut:` note on `PlayerStatisticsRepository::findThrowsInCountedGamesForPlayer()` says when to upgrade.
- Times come from one run on a development machine, so only their scale is meaningful.

## How to rerun

```bash
docker compose exec -T -w /var/www/html php php -d memory_limit=-1 vendor/bin/phpunit --group benchmark --filter PlayerProfileMemoryBaselineTest
```

The test fails when a row does not answer 200, and writes its table to `app/var/benchmarks/be-259-player-profile-memory.md`.

To measure the old code, keep this test and put the three source files back from `main` for the run:

```bash
git restore --source=6b6238f -- app/src/Repository/PlayerStatisticsRepository.php app/src/Repository/PlayerStatisticsRepositoryInterface.php app/src/Service/Player/PlayerThrowStatistics.php
# run the command above, then:
git restore -- app/src/Repository/PlayerStatisticsRepository.php app/src/Repository/PlayerStatisticsRepositoryInterface.php app/src/Service/Player/PlayerThrowStatistics.php
```

At 64M the old code is expected to fail the test at 60,000 throws; the table is written first. For the table without the
limit, also change `memory_limit=64M` in `startServer()` to `memory_limit=2G` for that run.
