---
status: accepted
---

# Avatars come from a folder by email, served through the backend

Every employee has a picture of their character, and a player should get theirs as their avatar without uploading anything (#233). Both repositories are public, so the pictures cannot live in git. They live in a folder outside the repository. The `AVATAR_DIR` environment variable names it: production mounts it read-only into the container, and dev uses the git-ignored `app/var/avatars`. Each file is named after the player's email in lower case, with `.png` or `.jpg` as extension. The backend looks the file up on every request to `GET /api/player/{id}/avatar` and streams it to the client, so the endpoint applies the same access rule as the profile. A new or changed picture is a new file and needs no migration.

Every player gets the picture that matches their email. Registration does not confirm that the address belongs to the person registering, and the email confirmation that #251 planned is cancelled. We accept that someone who registers with a colleague's address gets that colleague's picture. The app cannot delete an account; removing such an account is a manual database step.

## Considered Options

- A private Cloudflare R2 bucket, looked up by email. Rejected: a folder needs no external service and no credentials in each environment, and the pictures rarely change.
- Presigned URLs to an object store in the API responses. Rejected: a link works for anyone it is forwarded to until it expires, which bypasses the access rule.
- A public bucket or a public folder. Rejected: anyone who knows an email could fetch the picture.
- An avatar field on `User`, set at registration. Rejected: it needs a backfill for existing players and a manual update whenever a picture changes.
- A mapping file from email to file name. Rejected: one more file to keep in step with the folder.

## Consequences

- No existing response (Profile, lobby, game) says whether a player has an avatar. The frontend always requests the endpoint and draws a placeholder on `404`.
- The extension decides the `Content-Type`. The backend checks the file's bytes; when they do not match the extension, the backend logs it and treats the file as no avatar.
- When both `.png` and `.jpg` exist, `.png` wins.
- An email with a `/`, a `\` or a leading `.` never maps to a file.
- A player sees their own avatar and the admin sees every player's. When players may open each other's profiles (#252), the avatar follows the same rule.
- The endpoint answers `404` for a guest, a player without a file, a file whose bytes do not match its extension, and an avatar the caller may not see. All of these get the same error body, so the caller cannot tell which case applies.
- A missing or unreadable `AVATAR_DIR` answers `503` with its own error code and the error body of ADR 0006, and the backend logs it. A broken mount therefore does not look like "nobody has an avatar".
- Responses carry `Cache-Control: private, no-cache` and an `ETag` built from a hash of the file's bytes. The browser keeps the picture but asks the backend before each use, so the access rule runs every time and an unchanged picture costs a `304` without a body. A longer `max-age` was rejected: the browser cache is not split by who is logged in, so a player could get an avatar cached for the admin on the same browser.
- Someone copies the pictures into the mounted folder of each environment; the app has no upload.
- Functional tests point `AVATAR_DIR` at a temporary folder with generated pictures.
