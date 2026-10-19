# At most one game is started, and opening a lobby or starting a game aborts the others

The one tablet runs every game (ADR 0001), so at most one game is played at a time. Before this decision, a game stayed `started` until someone finished or aborted it. When the tablet closed in the middle of a game, the game stayed `started` for good and remained the current game of its players: `GET /api/player/current-game` returned it, and the player login response sent them to `/joined` for it.

Now every request that opens a lobby or makes a game `started` sets every other `started` game to `aborted` in the same transaction:

- `POST /api/room/create`, `POST /api/room/{id}/rematch` and the deprecated `POST /api/game/settings`, on every call, also when they return the open lobby (ADR 0003) instead of creating one.
- `POST /api/game/{gameId}/start` and `POST /api/game/{gameId}/rematch/start`: every `started` game except the one the request starts.
- `PATCH /api/game/{gameId}/reopen`: every `started` game except the reopened one.
- `DELETE /api/game/{gameId}/throw/delta` and the deprecated `DELETE /api/game/{gameId}/throw`, when undoing the last throw moves a `finished` game back to `started`: every `started` game except that one.

Aborting changes the status to `aborted` and nothing else, the same change `PATCH /api/game/{gameId}/abort` makes. Statistics count only `finished` games, so they stay the same. `finished` and `lobby` games are never aborted.

## Considered Options

- The current game lookup skips a `started` game when the player has a newer game. Rejected: stuck games stay `started` in the database, and the rule lives in one query that every other reader of the status would have to repeat.
- A timeout that aborts a game after N hours without a throw. Rejected: any N is arbitrary, and a long pause in a real game looks the same as an abandoned one.
- Abort the other games only when a game starts. Rejected: a player removed from the new lobby would see the dead game as their current game until the new lobby starts.
- A migration that aborts every `started` game. Rejected: a deploy during a real game would kill that game. Games that are already stuck get aborted by the tablet's next room create.

## Consequences

- `GameRepository::abortStartedGames` sets the games to `aborted` in one bulk update, optionally except one game id. `OpenLobbyService::findOrCreate` calls it first, so room create, rematch, rematch start and the deprecated settings endpoint all abort through the same path. `GameStartService` and `GameReopenService` call it after their status checks, and `GameThrowService` calls it when an undo moves a finished game back to `started`. Rematch start passes through both `OpenLobbyService` and `GameStartService`, so it runs the bulk update twice; the second run finds nothing to abort.
- The bulk update bypasses the EntityManager, so a `Game` entity the request already loaded keeps its old status in memory. It is a locking read, so it also aborts a game another request started and committed after this transaction's snapshot.
- Each call runs inside the request's `RetryingTransaction`, and a retry runs it again. Under REPEATABLE READ the bulk update takes next-key locks on the rows it reads, so MySQL either runs two parallel requests that each make a game `started` one after another or rolls one back as a deadlock. The retry then aborts the game the other request started. Either way, at most one game stays `started`.
- Undo runs in a plain transaction with a lock on the game, not in a `RetryingTransaction`. When it deadlocks with a parallel start, MySQL rolls one of them back: a rolled-back start runs again, a rolled-back undo returns an error to its client. Either way, two games never stay `started`.
- `PATCH /api/game/{gameId}/reopen` now runs in a `RetryingTransaction` and locks the game and its seats with `GameRepository::refreshWithSeatsForUpdate`, like start (ADR 0003). Each attempt loads the game by id.
- `GamePlayersRepository::findCurrentGameForPlayer` is unchanged. A game stops being anyone's current game once the tablet opens a lobby or starts another game.
- Opening a lobby on the tablet during a game without the Exit flow aborts that game for good, because reopen accepts only `finished` games.
