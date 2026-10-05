# One shared tablet runs every game under the admin account

A game is played around one shared tablet, which stays logged in as the single admin account. Every game action goes through that account: creating rooms, adding guests, reordering players, starting, scoring every throw (including guests' throws), undoing, finishing, and viewing statistics. Players register once from their own phone through an invitation and afterwards see only their own profile. So `ROLE_ADMIN` here means "the tablet", not "a moderator", and `ROLE_PLAYER` gets no access to game, room, or statistics endpoints.

## Considered Options

- Each player runs the game from their own phone. Rejected: some players have no phone, and one scoreboard avoids conflicting throws from several devices.

## Consequences

- `access_control` denies `/api` to everyone except the admin by default; player-facing endpoints (invitation processing, the future profile) need an explicit rule.
- The participant branch of `GameAccessService::assertPlayerInGameOrAdmin` is unreachable over HTTP while game routes stay admin-only.
