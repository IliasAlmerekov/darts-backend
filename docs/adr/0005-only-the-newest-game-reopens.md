# Only the newest game can be undone into play, reopened or rematched

ADR 0004 makes every request that opens a lobby or makes a game `started` abort the other started games. Undo of the throw that finished a game, reopen, rematch and rematch start took any game id, so an admin who reached an old game's summary, through the browser history or a second tab, aborted the game in progress by pressing Undo, Play again or Back to start there.

This decision narrows ADR 0004: an older game must not abort the game in progress. The **newest game** is the game with the greatest `gameId`, whatever its status. `DELETE /api/game/{gameId}/throw/delta`, when the undo would move a `finished` game back to `started`, and `PATCH /api/game/{gameId}/reopen` succeed only on the newest game when it is `finished`. `POST /api/room/{id}/rematch` succeeds only on the newest game when it is `finished` or `aborted`. Any other target gets a 409 with `GAME_NOT_NEWEST`; nothing is written and no game is aborted. Undo of a throw in a `started` game is unchanged.

Rematch also accepts an `aborted` newest game because of the frontend's exit flow: Exit first aborts the started game with `PATCH /api/game/{gameId}/abort`, then calls `POST /api/room/{id}/rematch` on that same game to open a lobby with the same participants. The aborted game is still the newest game at that point.

## Considered Options

- Refuse undo, reopen and rematch only while a game is in the lobby or started. Rejected: when no game runs, an old summary could still reopen a game older than the newest finished one, and the check would read every game's status instead of one id.
- Let the frontend hide Undo, Play again and Back to start on old summaries. Rejected: the backend must not rely on the client to keep the game in progress alive.

## Consequences

- `NewestGameGuard` holds the rule. `GameThrowService` calls it before an undo that would reopen a finished game, `GameReopenService` after its status checks, and `RematchService` first, before it opens a lobby.
- `GameRepository::findHighestGameId` reads the newest game id with a shared lock (FOR SHARE), so it sees games committed after the transaction's snapshot and blocks new games until the transaction ends. A parallel request that creates a game either waits or is rolled back as a deadlock; `RetryingTransaction` runs reopen and rematch again, and undo returns an error to its client, as in ADR 0004.
- An open lobby is always the newest game, because no game can be created while one is in the lobby (ADR 0003). Rematch of the finished game before an open lobby therefore returns 409 instead of the lobby, and so does a second Back to start or a Play again from the summary after Back to start. Rematch still reuses an open lobby that is older than the old game; only data from before ADR 0003 has one.
