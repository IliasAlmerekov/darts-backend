# Darts backend

Backend for a darts scoring app. A game runs on one shared tablet: players gather in a room, the tablet keeps score, and the app keeps statistics.

## Language

### People

**Admin**:
The single account logged in on the shared tablet. Every game action happens under this account: creating rooms, adding guests, starting, scoring, finishing, viewing statistics.
_Avoid_: Host, moderator, operator

**Player**:
A person who registered once from their own phone after scanning an invitation. A player sees only their own profile and statistics, never another player's. In a game they took part in, they also see every participant's display name and placement.
_Avoid_: User (for this meaning), member

**Guest**:
A participant without their own phone, created by the admin by name. A guest has no login and never acts in the app themselves.
_Avoid_: Anonymous player, offline player

**Participant**:
A player or guest who holds a place in a specific game at the moment of the request. Someone who left the room is no longer a participant.
_Avoid_: Member, joined player

**Seat**:
The record that makes a player or guest a participant of one game, with their position in the turn order. A participant has exactly one seat per game.
_Avoid_: Game player

**Username**:
The name a player picks when registering. It is unique across the whole app, and a guest has none.
_Avoid_: Login name, handle; "username" for a guest's name

**Display name**:
The name shown for a participant in a game: a player's username, or the name the admin typed for a guest. The admin cannot add a guest under a display name already used in that game; two participants can still share one when a player joins after a guest of the same name.
_Avoid_: Nickname, username (for guests)

### Game flow

**Lobby**:
The phase of a game before it starts. Participants join through the invitation, and the tablet sees them arrive. At most one game is in the lobby at a time. Creating a room or a rematch while a game is in the lobby returns that game.
_Avoid_: Waiting room (the player's screen in the frontend), pre-game

**Invitation**:
The QR code the admin shows for a room. Scanning it lets a player register or log in and join that room.
_Avoid_: Invite link, join code

**Waiting room**:
The screen on a player's phone after they join a room and before the admin starts the game.
_Avoid_: Joined page, lobby

**Current game**:
The game a player takes part in right now. A player has at most one; joining another game replaces it. A game stops being anyone's current game once the tablet opens a lobby or starts another game.
_Avoid_: active game

**Tablet game**:
The one game on the tablet that is in the lobby or started. A finished or aborted game is never the tablet game.
_Avoid_: Current game (for the tablet's game), active game

**Newest game**:
The game with the greatest game id, whatever its status. Undo into play, reopen and rematch act only on the newest game, so an older game never aborts the tablet game (ADR 0005).
_Avoid_: Latest game, last game

**Profile**:
The page a player lands on when they open the app without an invitation and without a current game. It shows their own recent finished games and statistics.
_Avoid_: Dashboard, home page

**Placement**:
A participant's rank in a finished game. The winner has placement 1.
_Avoid_: Position (the seat order before the game starts), rank

**Remembered login**:
A player's phone that stays signed in for 7 days after the player's last visit, so the player skips the login form. A new visit restarts the 7 days.
_Avoid_: Session, auto-login, remember me
