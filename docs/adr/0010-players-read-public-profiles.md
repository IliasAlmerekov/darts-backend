---
status: accepted
---

# Players read each other's Public profile

ADR 0001 lets a player see only their own profile: every game action runs on the shared tablet under the admin account, and `access_control` closes `/api` to players except for explicit rules. #252 lets a player open another player's profile. Every registered player may now open every other registered player's Public profile, read-only, through `GET /api/player/{id}/profile`. It holds the name (display name, else username), the avatar, and the statistics of the Profile. Recent games, the registration date and the email stay private.

The route takes a player id from the path, which `SECURITY.md` otherwise rates as a P0. Ids are sequential, so any logged-in player can reach every Public profile by counting. We accept that, since all players are colleagues. Sharing a game is not required.

## Considered Options

- Only players who shared a game may open each other's Public profile. Rejected: all players are colleagues, so the extra rule protects nothing they need protected.
- Return the full Profile of another player. Rejected: recent games, the registration date and the email stay with the player.

## Consequences

- `access_control` has a `ROLE_PLAYER` rule anchored to `^/api/player/\d+/profile$`. A path next to it still falls through to the `^/api` admin rule.
- The admin gets `403` on the Public profile, as on a player's own Profile. The tablet has the statistics endpoints instead.
- A guest, the admin account and an unknown id answer `404` with the same error body, so the endpoint does not reveal which ids belong to guests or the admin.
- The avatar route follows the same rule: every player gets the avatar of every registered player, and a guest or admin-account target answers the same `404` as an unknown id. The admin keeps access to every avatar (ADR 0009).
- Both routes stay read-only. Any write to another player's data, or any further field in the Public profile, needs a new decision.
