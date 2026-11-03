---
status: accepted
---

# Avatars come from R2 by email, served through the backend

Every employee has a character picture, and a player should get theirs without uploading anything (#233). Both repositories are public, so the pictures cannot live in git. They live in a private Cloudflare R2 bucket under `avatars/<email>`, the player's email in lower case, without a file extension; the object's `Content-Type` says whether it is a PNG or a JPEG. The backend looks the object up on every request to `GET /api/player/{id}/avatar` and streams it to the client, so the R2 keys stay on the server and the endpoint applies the same access rule as the profile. A new or changed picture is a new object and needs no migration.

## Considered Options

- A folder outside git, mounted read-only into the container. Rejected: the pictures would have to be copied to every environment by hand.
- Presigned R2 URLs in the API responses. Rejected: a link works for anyone it is forwarded to until it expires, which bypasses the access rule.
- A public bucket. Rejected: anyone who knows an email could fetch the picture.
- An avatar field on `User`, set at registration. Rejected: it needs a backfill for existing players and a manual update whenever a picture changes.

## Consequences

- No list response says whether a player has an avatar. The frontend always requests the endpoint and draws a placeholder on `404`; listing a lobby would otherwise cost one R2 request per participant.
- The endpoint answers `404` for a guest, a player without a picture, a player who has not confirmed their email (#251), a player whose object has another `Content-Type` than PNG or JPEG, and an avatar the caller may not see. An unreachable R2 answers `503` with the error body of ADR 0006.
- Responses carry `Cache-Control: private, max-age=86400` and an `ETag`, so a changed picture can take up to a day to show.
- Functional tests read from an in-memory fake; dev reads from its own bucket with keys in `.env.local`.
