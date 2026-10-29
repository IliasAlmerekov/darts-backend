# One shared tablet runs every game under the admin account

A game is played around one shared tablet, which stays logged in as the single admin account. Every game action goes through that account: creating rooms, adding guests, reordering players, starting, scoring every throw (including guests' throws), undoing, finishing, and viewing statistics. Players register once from their own phone through an invitation and afterwards see only their own profile. So `ROLE_ADMIN` here means "the tablet", not "a moderator", and `ROLE_PLAYER` gets no access to game, room, or statistics endpoints.

## Considered Options

- Each player runs the game from their own phone. Rejected: some players have no phone, and one scoreboard avoids conflicting throws from several devices.

## Consequences

- `access_control` denies `/api` to everyone except the admin by default; player-facing endpoints (invitation processing, the future profile) need an explicit rule.
- Game and invitation actions that previously checked participation now call `assertAdmin()` as well. Other room operations rely on `access_control` for admin-only access.
- Only the tablet follows a running game. No second live viewer is supported: no second admin tab, second device, or wall scoreboard. The tablet gets the scoreboard from the responses to its own throw and undo requests.
- The room stream serves only the lobby. It carries `players` events and closes when the game leaves the lobby. A live channel for throws needs a new ADR (see #44).
