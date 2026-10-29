# BE-701 EXPLAIN for `/api/players/stats`

- Generated at: 2026-03-10 13:32:52 UTC
- Scope: inspect `App\Repository\RoundThrowsRepository::getPlayerStatistics()` behind `GET /api/players/stats`.
- Environment: local Docker stack from repository root (`php` + `mysql`), dev database `testphp_db`.
- Dataset snapshot: `round_throws=1502`, `finished games=35`, `finished rounds=192`, `non-guest users=8`.

## Doctrine SQL

Source method: `app/src/Repository/RoundThrowsRepository.php`

```sql
SELECT
    u0_.id AS id_0,
    u0_.display_name AS display_name_1,
    COUNT(DISTINCT g1_.game_id) AS sclr_2,
    SUM(CASE WHEN r2_.is_bust = 1 THEN 0 ELSE r2_.value END) AS sclr_3,
    COUNT(DISTINCT r3_.round_id) AS sclr_4,
    (
        SUM(CASE WHEN r2_.is_bust = 1 THEN 0 ELSE r2_.value END)
        / NULLIF(COUNT(DISTINCT r3_.round_id), 0)
    ) AS sclr_5
FROM round_throws r2_
INNER JOIN `user` u0_ ON r2_.player_id = u0_.id
INNER JOIN game g1_ ON r2_.game_id = g1_.game_id
INNER JOIN round r3_ ON r2_.round_id = r3_.round_id
WHERE g1_.status = ?
  AND u0_.is_guest = 0
  AND r3_.finished_at IS NOT NULL
GROUP BY u0_.id, u0_.display_name
ORDER BY sclr_5 DESC
LIMIT 20
```

Bound parameter:

- `status = 'finished'`

## Index snapshot

Relevant indexes at inspection time:

- `round_throws`: `PRIMARY(throw_id)`, `KEY(game_id)`, `KEY(round_id)`, `KEY(player_id)`
- `round`: `PRIMARY(round_id)`, `KEY(game_id)`
- `game`: `PRIMARY(game_id)`, `KEY(winner_id)`
- `user`: `PRIMARY(id)`, `UNIQUE(email)`, `UNIQUE(username)`

Missing indexes for this query shape:

- `game(status)`
- `round(finished_at)` or a composite path involving `finished_at`
- `user(is_guest)`
- any covering/composite index on `round_throws` aligned with this aggregation path

## EXPLAIN summary

`EXPLAIN FORMAT=JSON` shows:

- driving table is `round_throws` with `access_type: ALL`
- joins to `round`, `game`, `user` are `eq_ref` by primary key
- `grouping_operation.using_temporary_table = true`
- `grouping_operation.using_filesort = true`
- outer `ordering_operation.using_filesort = true`

Key JSON excerpts:

```json
{
  "ordering_operation": {
    "using_filesort": true,
    "grouping_operation": {
      "using_temporary_table": true,
      "using_filesort": true,
      "nested_loop": [
        {
          "table": {
            "table_name": "r2_",
            "access_type": "ALL",
            "rows_examined_per_scan": 1434
          }
        }
      ]
    }
  }
}
```

`EXPLAIN ANALYZE` on the same SQL:

- `Table scan on r2_`: `actual rows=1502`
- after `round.finished_at IS NOT NULL`: `1377` rows remain
- after `game.status = 'finished'`: `779` rows remain
- after `user.is_guest = 0`: `424` rows remain
- sort for grouping: `Sort: u0_.id, u0_.display_name`
- final sort: `Sort: sclr_5 DESC`
- total observed runtime on this dataset: about `3.98 ms`

## Bottleneck breakdown

### Join

- The joins themselves are not the main bottleneck.
- All three joins are `eq_ref` lookups by PK, which is the cheapest join type MySQL can use here.
- Cost comes from how often they are executed: MySQL starts from a full scan of `round_throws`, then performs PK lookups for each qualifying row.

### Group By

- `GROUP BY u.id, u.display_name` requires MySQL to reorganize the filtered throw rows by player.
- `EXPLAIN` shows a temporary table plus filesort for the grouping stage.
- This is a real cost center because aggregation happens over throw-level rows, not over already pre-aggregated round/player data.

### Distinct

- `COUNT(DISTINCT g.game_id)` and `COUNT(DISTINCT r.round_id)` add extra deduplication work inside the aggregate.
- Because multiple throws belong to the same round/game, MySQL cannot just count rows; it must deduplicate per player per aggregate key.
- This amplifies the cost of the grouping stage and reinforces the temporary-table requirement.

### Sort

- `ORDER BY scoreAverage DESC` sorts by a computed aggregate alias.
- MySQL cannot satisfy that from an index, so it performs another filesort after aggregation.
- The query therefore pays for two sort-heavy phases:
  - one to group/aggregate
  - one to order the aggregated result set by computed average

## Conclusion

On the inspected dataset, the dominant bottleneck is:

1. full scan of `round_throws`
2. aggregation over throw-level rows with `GROUP BY` + two `COUNT(DISTINCT ...)`
3. final sort by computed aggregate

The joins are structurally cheap (`eq_ref`) and are not the first optimization target. The first optimization candidates are the aggregation shape and the amount of throw-level data that must reach the grouping stage.

## How to rerun

From the repository root:

- `docker compose exec -T -e DATABASE_URL=mysql://dev_user:devuser123@mysql:3306/testphp_db?serverVersion=8.0.32\&charset=utf8mb4 -w /var/www/html php php -r 'require "vendor/autoload.php"; $kernel = new App\Kernel("dev", false); $kernel->boot(); $em = $kernel->getContainer()->get("doctrine")->getManager(); $qb = $em->createQueryBuilder(); $qb->select("u.id AS playerId", "u.displayName AS username", "COUNT(DISTINCT g.gameId) AS gamesPlayed", "SUM(CASE WHEN rt.isBust = true THEN 0 ELSE rt.value END) AS totalValue", "COUNT(DISTINCT r.roundId) AS roundsFinished", "(SUM(CASE WHEN rt.isBust = true THEN 0 ELSE rt.value END) / NULLIF(COUNT(DISTINCT r.roundId), 0)) AS scoreAverage")->from(App\Entity\RoundThrows::class, "rt")->innerJoin("rt.player", "u")->innerJoin("rt.game", "g")->innerJoin("rt.round", "r")->andWhere("g.status = :status")->andWhere("u.isGuest = false")->andWhere("r.finishedAt IS NOT NULL")->setParameter("status", App\Enum\GameStatus::Finished)->groupBy("u.id", "u.displayName")->orderBy("scoreAverage", "DESC")->setFirstResult(0)->setMaxResults(20); echo $qb->getQuery()->getSQL(), PHP_EOL;'`
- `docker compose exec -T mysql mysql -udev_user -pdevuser123 -D testphp_db -e "EXPLAIN FORMAT=JSON SELECT u0_.id AS playerId_0, u0_.display_name AS username_1, COUNT(DISTINCT g1_.game_id) AS sclr_2, SUM(CASE WHEN r2_.is_bust = 1 THEN 0 ELSE r2_.value END) AS sclr_3, COUNT(DISTINCT r3_.round_id) AS sclr_4, (SUM(CASE WHEN r2_.is_bust = 1 THEN 0 ELSE r2_.value END) / NULLIF(COUNT(DISTINCT r3_.round_id), 0)) AS sclr_5 FROM round_throws r2_ INNER JOIN user u0_ ON r2_.player_id = u0_.id INNER JOIN game g1_ ON r2_.game_id = g1_.game_id INNER JOIN round r3_ ON r2_.round_id = r3_.round_id WHERE g1_.status = 'finished' AND u0_.is_guest = 0 AND r3_.finished_at IS NOT NULL GROUP BY u0_.id, u0_.display_name ORDER BY sclr_5 DESC LIMIT 20;"`
- `docker compose exec -T mysql mysql -udev_user -pdevuser123 -D testphp_db -e "EXPLAIN ANALYZE SELECT u0_.id AS playerId_0, u0_.display_name AS username_1, COUNT(DISTINCT g1_.game_id) AS sclr_2, SUM(CASE WHEN r2_.is_bust = 1 THEN 0 ELSE r2_.value END) AS sclr_3, COUNT(DISTINCT r3_.round_id) AS sclr_4, (SUM(CASE WHEN r2_.is_bust = 1 THEN 0 ELSE r2_.value END) / NULLIF(COUNT(DISTINCT r3_.round_id), 0)) AS sclr_5 FROM round_throws r2_ INNER JOIN user u0_ ON r2_.player_id = u0_.id INNER JOIN game g1_ ON r2_.game_id = g1_.game_id INNER JOIN round r3_ ON r2_.round_id = r3_.round_id WHERE g1_.status = 'finished' AND u0_.is_guest = 0 AND r3_.finished_at IS NOT NULL GROUP BY u0_.id, u0_.display_name ORDER BY sclr_5 DESC LIMIT 20;"`
