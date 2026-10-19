# At most one game is in the lobby, and room create and rematch return it

The one tablet runs every game (ADR 0001), so the system needs one lobby at most. Before this decision, every room create and every rematch inserted a new lobby game, and each reload, new tab, or repeated Back to start left another one in the database. Now `POST /api/room/create`, `POST /api/room/{id}/rematch`, and `POST /api/game/{gameId}/rematch/start` return the open lobby when one exists and create a lobby only when none does. Room create applies its start score, double out and triple out to the open lobby and keeps the lobby's players. Rematch adds the old game's missing players after the players already there and copies the old game's settings. A unique index on the generated column `game.open_lobby`, which is 1 for `status = 'lobby'` and NULL otherwise, enforces the rule, because MySQL 8 has no partial indexes. A request whose insert the index rejects re-reads with a locking read and returns the lobby the other request created. The migration that adds the index first sets every game in the lobby to `aborted`.

## Considered Options

- An application lock around room create and rematch. Rejected: it protects only the code that takes it. Any other path that inserts a lobby game, or a later change that forgets the lock, could still open a second lobby. The index holds for every writer.
- Rematch aborts the open lobby and opens a new one. Rejected: players who scanned the QR code after Back to start would drop out of the room, and Play again would start without them.

## Consequences

- `OpenLobbyService` is the one find-or-create path. `GameRepository::insertLobby` inserts through DBAL, because a failed ORM flush closes the EntityManager and the request still needs it after the index rejects the insert.
- The deprecated `POST /api/game/settings` goes through the same path, so it also returns the open lobby and applies its settings to it.
- `OpenLobbyService` locks the lobby row through its primary key until the caller's transaction ends, so room create and rematch run in a transaction. Rematch reads the lobby's seats with a shared lock, because a plain read inside the transaction could miss seats another request committed and seat a player twice.
- A unique index on `game_players(game_id, player_id)` gives a player at most one seat per game, whatever path inserts it. Its migration deletes existing duplicate seats and keeps the one with the lowest `game_player_id`. A request that loses a seating race through the ORM gets an error, not a second seat, because a failed flush closes the EntityManager.
- Room create ignores `previousGameId`, `playerIds` and `excludePlayerIds` while a game is in the lobby.
- Players who were in a lobby game when the migration ran have no current game afterwards, because `GamePlayersRepository::findCurrentGame` returns only `lobby` and `started` games.
- An old lobby stays open until it starts or a rematch reuses it. A timeout or a Close room button needs its own decision.
