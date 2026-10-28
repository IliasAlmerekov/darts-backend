---
status: accepted
---

# Cross-site writes are stopped by an origin check, not by server-issued CSRF tokens

`SameSite=Lax` cookies were the only defence against cross-site writes. `Lax` treats every subdomain of the same registrable domain as same-site, so a page on a sibling subdomain could write with the victim's cookies, and it does not cover login CSRF: a hostile site could log a victim's browser into the attacker's player account. `GET /api/csrf` handed out `authenticate` and `user_registration` tokens, but no endpoint checked them (#135).

The API compares where a request comes from with its own origin. `CrossOriginRequestSubscriber` answers 403 `CROSS_ORIGIN_REQUEST` to a `POST`, `PUT`, `PATCH`, or `DELETE` under `/api` when `Sec-Fetch-Site` is present and not `same-origin`, or, without that header, when `Origin` is present and differs from the request's scheme and host. A request with neither header passes, because browsers send at least one on every unsafe request, while tests, curl, and server-side clients send none. The subscriber runs on kernel.request before the firewall, and reads no database row and no session.

Login goes through Symfony's `SameOriginCsrfTokenManager`: `form_login.enable_csrf` is on, and `authenticate` is a stateless token id. The manager accepts the fixed token value `csrf-token` when the browser's origin headers match; with no origin information it refuses, so a login needs a browser header. A failed check maps to the same 403 and code as the subscriber. `GET /api/csrf` is removed.

## Considered Options

- Server-issued tokens checked on every write (the "enforce" option of #135). Rejected: every write needs a token fetched first or stored in the session, which costs a round trip and session state, and the frontend must carry the token on every call.
- A custom header such as `X-Requested-With` on every write. Rejected: a custom header makes the browser send a CORS preflight before every throw, which slows the game on the tablet.
- Keep `SameSite=Lax` and CORS only. Rejected: it leaves login CSRF and sibling subdomains open.
- Remove `/api/csrf` and document `SameSite=Lax` as the defence. Rejected for the same reason.

## Consequences

- Weakening the check is a P0 (`SECURITY.md`, `### CSRF and CORS`).
- The origin comes from the request's own scheme and host, so production needs `SYMFONY_TRUSTED_PROXIES` (#108) for the proxy's forwarded headers. The dev frontend works through the vite proxy, which sets `Origin` to the API target and rewrites `Host` to match.
- Login needs the field `_csrf_token=csrf-token`. The frontend sends it (IliasAlmerekov/darts#371 and a follow-up), so the backend deploys after, or together with, that change.
- Registration takes no `_csrf_token`; an unknown key stays `UNKNOWN_FIELD`. It is still covered by the subscriber.
- A cross-origin request is refused before login throttling counts it.
