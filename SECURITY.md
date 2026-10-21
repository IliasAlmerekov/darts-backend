# Security

## Reporting a vulnerability

Report a vulnerability privately through a GitHub security advisory:
https://github.com/IliasAlmerekov/darts-backend/security/advisories/new.
Keep it out of public issues, pull requests, and comments. Only `main` gets security fixes.

## Security review guidelines

Review every diff as an attacker would. Three attackers matter:

- an anonymous client on the internet, who reaches only the public routes;
- a logged-in player (`ROLE_PLAYER`), who must see only their own profile and current game
  and must never reach a game, room, or statistics endpoint (ADR 0001);
- a hostile website open in the browser of the admin tablet or of a player, which sends
  requests that carry their cookies.

A security finding names the attacker, the request they send, and what they gain. Each
rule below gives the severity of a break. P0 is Critical, P1 is High, and P2 is Medium.

### Not reportable

- Actions of the admin account (`ROLE_ADMIN`). It is the shared tablet and is trusted
  (ADR 0001).
- Values in `app/.env.local` and other `*.local` env files. Git ignores them, and they
  never ship.
- Local development defaults in `app/.env`, such as the empty `APP_SECRET` and the SQLite
  `DATABASE_URL`.
- The two `GET` routes that write, listed under `### CSRF and CORS`, unless a diff widens
  what they write.
- Swagger UI and `/api/doc.json` in the dev environment.

### Access control

`access_control` in `app/config/packages/security.yaml` applies the first matching rule,
and the last one, `^/api` for `ROLE_ADMIN`, closes every route nobody listed. A new or
widened rule above it that opens a path to `PUBLIC_ACCESS` or `ROLE_PLAYER` is a P0 unless
all of these hold:

- its regex is anchored with `$` and matches only the intended route;
- the PR names the reason, and an ADR when the rule changes who may do what;
- a functional test shows the next role down gets 401 or 403 on that route.

A renamed or moved route that lands under a public prefix is the same P0.

A player endpoint takes the user from the security token (`$this->getUser()`). An endpoint
that reads a user, game, or seat id from the path, query, or body and returns or changes
another player's data is a P0.

### CSRF and CORS

The API validates no CSRF token: `form_login` sets `enable_csrf: false`, and nothing checks
the tokens `GET /api/csrf` hands out. Most write endpoints take no body or bind it with
`#[MapRequestPayload]` without `acceptFormat`, which also accepts form bodies, so CORS
preflight does not stop a cross-site write. The defences are:

- the session and remember-me cookies are `SameSite=Lax` in prod, so a cross-site `POST`,
  `PUT`, `PATCH`, or `DELETE` arrives without them;
- `GET` and `HEAD` never change state, because `Lax` still sends cookies on a top-level
  cross-site `GET`;
- CORS lets only origins matching `CORS_ALLOW_ORIGIN` read responses with credentials.

A change that weakens one of them is a P0: `samesite: none` or no `samesite` on either
cookie, a new `GET` route that writes to the database or the session, or a broader CORS
origin. Two `GET` routes write today: `/api/invite/join/{uuid}` stores the invitation in
the session, and `/api/login/success` joins the player to that invitation's game.
`CORS_ALLOW_ORIGIN` is a regex; a committed value without `^` and `$` anchors, or one that
matches any host, is a P0.

### Authentication and sessions

Remembered login follows ADR 0002: only `ROLE_PLAYER` gets the remember-me cookie. A path
that grants it to the admin is a P0. Removing or loosening login throttling (5 attempts per
minute) or the `registration` rate limiter is a P1. Password hashing stays `auto`; a weaker
hasher outside `when@test` is a P0.

The invitation UUID is a bearer secret. It is a random `UuidV4`, it appears in no log, and
joining a game uses the game id the join link stored in the session. A join that trusts a
game id from the client is a P0.

### Input and queries

Request data binds into DTOs under `app/src/Dto/` with Validator constraints, and entities
stay out of request binding. A request field without bounds (length, range, choice) that
reaches the database or a loop is a P1. SQL and DQL bind every value as a parameter; a
value concatenated into a query string is a P0.

### Information disclosure

`App\Security\ErrorController` answers every exception that does not implement
`ApiExceptionInterface` with a generic body. The message of an `ApiHttpException` reaches
the client, so it carries no SQL, file path, stack trace, or another user's email. A
leak of that kind is a P1. Profiler, Swagger UI, or any debug route reachable outside the
dev environment is a P0.

### Secrets and logs

A secret committed in any file is a P0. `app/.env` holds only local development defaults;
real values come from the environment or `APP_SECRET_FILE`. A password, session id,
remember-me cookie, CSRF token, or invitation UUID written to a log is a P1.

### Dependencies

A new or upgraded Composer package needs the user's approval (`AGENTS.md`, `## Guardrails`)
and a passing `composer audit`. A package that is abandoned, or that the change could do
without, is a P2.
