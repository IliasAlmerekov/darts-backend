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

**Position**:
A seat's place in the turn order of its game. No two seats of the same game share a position.
_Avoid_: Placement (the rank after the game), order number

**Username**:
The name a player picks when registering. It is unique across the whole app, and a guest has none.
_Avoid_: Login name, handle; "username" for a guest's name

**Display name**:
The name shown for a participant in a game: a player's display name, else their username, or the name the admin typed for a guest. A participant keeps the display name their seat got when they joined the game. The admin cannot add a guest under a display name already used in that game; two participants can still share one when a player joins after a guest of the same name.
_Avoid_: Nickname, username (for guests)

### Game flow

**Lobby**:
The phase of a game before it starts. Participants join through the invitation, and the tablet sees them arrive. At most one game is in the lobby at a time. Creating a room or a rematch while a game is in the lobby returns that game. Seats change only in the lobby: once a game has started, the admin can no longer add a guest, remove a participant or reorder the positions.
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

**Manual end**:
The admin ends a started game without a checkout; placements follow the remaining scores.
_Avoid_: Finish (that is the turn with a checkout), force finish

**Abort**:
The admin stops a started game without placements; the game keeps its throws but no one can play or undo in it again.
_Avoid_: Cancel, quit

**Rematch**:
A new lobby with the participants of the newest game, once it is finished or aborted. After a finished game the last placed participant throws first.
_Avoid_: Replay, new round

**Out rule**:
The kind of dart a game's checkout must be: single, double or triple out. A game has exactly one.
_Avoid_: Out mode, game mode

**Dead score**:
A remaining score from which no checkout is possible under the game's out rule: 1 in double out, 1 or 2 in triple out.
_Avoid_: Blocked score, unfinishable score

**Turn**:
Up to three darts one participant throws in a row. A bust or a checkout ends the turn early.
_Avoid_: Visit, throw (for the whole turn)

**Bust**:
A dart that leaves a negative score, a dead score, or zero without the dart the out rule asks for. The whole turn then scores nothing and the participant's score returns to where the turn began (WDF rule 2.05).
_Avoid_: Overthrow, no score

**Profile**:
The page a player lands on when they open the app without an invitation and without a current game. It shows their own recent finished games and statistics.
_Avoid_: Dashboard, home page

**Registration date**:
The moment a player registered. Players who registered before the app stored it count from their first game; a player without a game has none. Guests and the admin never have one.
_Avoid_: Member since (the frontend label), created at, sign-up date

**Placement**:
A participant's rank in a game. A participant gets it at their checkout, or when the game ends without one. The winner has placement 1. Undoing a throw or reopening the game takes the placement away from everyone whose score is above zero again, and the win with it; the others keep theirs.
_Avoid_: Position (the turn order), rank

**Remembered login**:
A player's phone that stays signed in for 7 days after the player's last visit, so the player skips the login form. A new visit restarts the 7 days.
_Avoid_: Session, auto-login, remember me

### Statistics

**Checkout**:
The dart that finishes a game for a participant: it brings their score to exactly zero under the game's out rule (single, double or triple out).
_Avoid_: Finish (for the single dart), out

**Checkout attempt**:
A dart thrown while the participant's remaining score could be checked out with that one dart under the game's out rule. The app records what a dart hit, not what it aimed at, so the remaining score stands in for the aim.
_Avoid_: Double attempt, finish attempt

**Finish**:
The turn that ends with a checkout. Its value is the participant's remaining score when the turn began; the highest finish is the one with the greatest value.
_Avoid_: Checkout (for the whole turn), high out

**Player statistics**:
The games played and the three-dart average of one player, counted over every finished game in which they threw. Guests have none.
_Avoid_: Player stats, lifetime stats, profile stats

**Three-dart average**:
The points a participant scored divided by the darts they threw, times three. A bust turn counts as three darts and no points; any other turn counts only the darts actually thrown, so a checkout or a manual end can cut it short.
_Avoid_: Round average, score average, points per turn
