# Players stay logged in through remember_me tokens stored in the database

Players open the app by scanning a QR code on their own phone, often days apart, and should not have to log in each time. A player's login now survives for 7 days after their last visit through Symfony `remember_me` with the Doctrine token provider. Each restored login reissues the cookie, so an active player never sees the login form, while one who stays away longer than a week logs in again. Registration will log the player in the same way once #81 lands. Until then a newly registered player logs in once by hand.

## Considered Options

- Store sessions in the database and raise their lifetime to 7 days. Rejected: every QR scan opens an anonymous session that holds the invitation, so the table would fill with sessions nobody logs into, and a session cannot be revoked per player the way a token row can.
- Move registration and login to Keycloak. Rejected for now: it adds a Java server and its own database to run, an OIDC redirect on every QR scan, a themed login page outside the React app, and a password migration for existing players. Revisit when the app needs social login, two-factor authentication, or one account across several apps.

## Consequences

- The admin and players share one firewall, so a `LoginSuccessEvent` listener grants remember_me to `ROLE_PLAYER` only. The shared tablet keeps its current login behaviour (ADR 0001).
- The token table needs a migration.
- The login form's "Remember me" checkbox is removed, because a player is always remembered.
