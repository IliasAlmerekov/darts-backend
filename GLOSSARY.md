# Darts backend

Backend for a darts scoring app. A game runs on one shared tablet: players gather in a room, the tablet keeps score, and the app keeps statistics.

## Language

### People

**Admin**:
The single account logged in on the shared tablet. Every game action happens under this account: creating rooms, adding guests, starting, scoring, finishing, viewing statistics.
_Avoid_: Host, moderator, operator

**Player**:
A person who registered once from their own phone after scanning an invitation. A player sees only their own profile, games, and statistics, never another player's.
_Avoid_: User (for this meaning), member

**Guest**:
A participant without their own phone, created by the admin by name. A guest has no login and never acts in the app themselves.
_Avoid_: Anonymous player, offline player

**Participant**:
A player or guest who holds a place in a specific game at the moment of the request. Someone who left the room is no longer a participant.
_Avoid_: Member, joined player

### Game flow

**Invitation**:
The QR code the admin shows for a room. Scanning it lets a player register or log in and join that room.
_Avoid_: Invite link, join code

**Profile**:
The page a player lands on when they open the app without an invitation. It shows their own recent games and statistics.
_Avoid_: Dashboard, home page
