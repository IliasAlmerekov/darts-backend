# Backend Dokumentation - Darts Application

## Inhaltsverzeichnis

1. [Überblick](#überblick)
2. [Architektur](#architektur)
3. [API Endpunkte](#api-endpunkte)
   - [Security & Authentication](#security--authentication)
   - [Registration](#registration)
   - [Game Room Management](#game-room-management)
   - [Invitation System](#invitation-system)
   - [Game Management](#game-management)
   - [Statistics](#statistics)
4. [Services & Business Logic](#services--business-logic)
5. [Datenmodell](#datenmodell)
6. [Workflow & Spielablauf](#workflow--spielablauf)

---

## Überblick

Das Backend ist eine **Symfony 7 REST API** für eine Darts-Anwendung. Es verwaltet:

- Benutzer-Registrierung und -Authentifizierung
- Spielraum-Erstellung und -Verwaltung
- Einladungssystem für Spiele
- Spiellogik (Würfe, Rundenmanagement, Punkteberechnung)
- Statistiken und Ranglisten

**Technologie-Stack:**

- PHP 8.2+
- Symfony 7
- Doctrine ORM
- Docker/Docker Compose

---

## Architektur

Das Backend folgt einer klaren **Service-Oriented Architecture (SOA)**:

```
Controller → Services → Repositories → Entities
```

- **Controller**: Empfangen HTTP-Requests, validieren Input, rufen Services auf
- **Services**: Enthalten die Business Logic (z.B. Spielstart, Wurf-Verarbeitung)
- **Repositories**: Datenbankzugriff und Queries
- **Entities**: Doctrine ORM Entities (Game, User, Round, RoundThrows, etc.)
- **DTOs**: Data Transfer Objects für Request/Response Validierung

---

## API Endpunkte

### Security & Authentication

#### 1. Login

**Endpunkt:** `POST /api/login`

**Beschreibung:** Authentifiziert einen Benutzer via Symfony Security.

**Request:**

```json
{
  "username": "player1",
  "password": "password123"
}
```

**Response:** Redirect zu `/api/login/success`

---

#### 2. Login Success

**Endpunkt:** `GET /api/login/success`

**Beschreibung:** Verarbeitet erfolgreiche Logins und leitet basierend auf Rolle/Kontext weiter.

**Logik:**

1. Prüft Benutzerrolle:

   - **ROLE_ADMIN**: Redirect zu `/start`
   - **Mit Einladung**: Fügt Spieler zum Spiel hinzu → Redirect zu `/joined`
   - **Standard Player**: Redirect zu `/playerprofile`

2. **Einladungs-Verarbeitung:**
   - Liest `invitation_uuid` aus Session
   - Findet zugehöriges Spiel
   - Fügt Spieler zum Spiel hinzu (falls nicht bereits vorhanden)
   - Erstellt `GamePlayers` Eintrag

**Response:**

```json
{
  "success": true,
  "roles": ["ROLE_PLAYER"],
  "id": 123,
  "username": "player1",
  "redirect": "/playerprofile"
}
```

---

#### 3. Logout

**Endpunkt:** `GET /api/logout`

**Beschreibung:** Meldet den Benutzer ab (via Symfony Security Firewall).

---

### Registration

#### Registrierung

**Endpunkt:** `POST /api/register`

**Beschreibung:** Erstellt einen neuen Benutzer-Account.

**Request:**

```json
{
  "username": "newplayer",
  "email": "newplayer@example.com",
  "plainPassword": "securepassword"
}
```

**Validierung:**

- Username: erforderlich
- Email: gültiges Format
- Password: Mindestlänge (definiert in `RegistrationFormType`)

**Logik:**

1. Validiert Request via Symfony Form (`RegistrationFormType`)
2. Hasht Passwort mit `UserPasswordHasher`
3. Setzt Rolle auf `ROLE_PLAYER`
4. Speichert User in Datenbank

**Response (Erfolg):**

```json
{
  "success": true,
  "message": "Registrierung erfolgreich",
  "redirect": "/"
}
```

**Response (Fehler):**

```json
{
  "success": false,
  "message": "Registrierung fehlgeschlagen. Bitte überprüfe deine Eingaben.",
  "errors": {
    "username": ["Benutzername bereits vergeben"],
    "email": ["E-Mail ungültig"]
  }
}
```

---

### Game Room Management

#### 1. Spielraum erstellen

**Endpunkt:** `POST /api/room/create`

**Beschreibung:** Erstellt einen neuen Spielraum (Game).

**Request (optional):**

```json
{
  "previousGameId": 42,
  "playerIds": [1, 2, 3],
  "excludePlayerIds": [4]
}
```

**Parameter:**

- `previousGameId`: ID eines vorherigen Spiels (für Rematch)
- `playerIds`: Liste von Spieler-IDs, die hinzugefügt werden sollen
- `excludePlayerIds`: Spieler, die ausgeschlossen werden sollen

**Logik (GameRoomService):**

1. Erstellt neues `Game` Entity
2. Setzt Datum auf aktuelles Datum
3. Status: `GameStatus::Lobby` (default)
4. Falls `previousGameId` vorhanden:
   - Kopiert Spieler vom vorherigen Spiel (optional gefiltert)
5. Fügt Spieler hinzu via `PlayerManagementService`

**Response:**

```json
{
  "success": true,
  "gameId": 123
}
```

---

#### 2. Spieler verlässt Raum

**Endpunkt:** `DELETE /api/room/{id}`

**Beschreibung:** Entfernt einen Spieler aus einem Spielraum.

**Query Parameter:**

```
?playerId=123
```

**Oder Request Body:**

```json
{
  "playerId": 123
}
```

**Logik (PlayerManagementService):**

1. Findet `GamePlayers` Eintrag
2. Löscht Spieler aus dem Spiel
3. Benachrichtigt andere Spieler via SSE Stream

**Response (Erfolg):**

```json
{
  "success": true,
  "message": "Player removed from the game"
}
```

**Response (Fehler):**

```json
{
  "success": false,
  "message": "Player not found in this game"
}
```

---

#### 3. Server-Sent Events Stream

**Endpunkt:** `GET /api/room/{id}/stream`

**Beschreibung:** Eröffnet einen SSE Stream für Echtzeit-Updates des Spielraums.

**Logik (SseStreamService):**

- Sendet Ereignisse bei:
  - Spieler beitritt/verlässt
  - Spiel startet
  - Wurf registriert
  - Spiel endet

**Response (Stream):**

```
event: player-joined
data: {"playerId": 123, "username": "player1"}

event: game-started
data: {"gameId": 42, "status": "started"}
```

---

#### 4. Rematch erstellen

**Endpunkt:** `POST /api/room/{id}/rematch`

**Beschreibung:** Erstellt ein neues Spiel mit denselben Spielern.

**Logik (RematchService):**

1. Findet Original-Spiel
2. Erstellt neues Spiel via `GameRoomService.createGameWithPreviousPlayers()`
3. Kopiert alle Spieler aus dem Original-Spiel

**Response:**

```json
{
  "success": true,
  "gameId": 124
}
```

---

### Invitation System

#### 1. Einladung erstellen

**Endpunkt:** `POST /api/invite/create/{id}`

**Beschreibung:** Erstellt einen Einladungslink für ein Spiel.

**Logik:**

1. Prüft, ob bereits eine Einladung existiert
2. Falls nicht: Erstellt neue `Invitation` mit UUID
3. Generiert Einladungslink: `/api/invite/join/{uuid}`

**Response:**

```json
{
  "success": true,
  "gameId": 42,
  "invitationLink": "/api/invite/join/550e8400-e29b-41d4-a716-446655440000"
}
```

---

#### 2. Einladung beitreten

**Endpunkt:** `GET /api/invite/join/{uuid}`

**Beschreibung:** Verarbeitet Einladungslink-Klicks.

**Logik:**

1. Findet `Invitation` via UUID
2. Speichert `invitation_uuid` und `game_id` in Session
3. Redirect zu Frontend (`http://localhost:5173/`)
4. Frontend leitet zu Login → `login/success` verarbeitet Einladung

**Response:** HTTP Redirect zu Frontend

---

#### 3. Einladung verarbeiten

**Endpunkt:** `POST /api/invite/process`

**Beschreibung:** Fügt authentifizierten User zum Spiel hinzu (nach Login).

**Logik:**

1. Liest `game_id` aus Session
2. Erstellt `GamePlayers` Eintrag für aktuellen User
3. Löscht Einladungsdaten aus Session

**Response:** Redirect zu Warteraum

---

### Game Management

#### 1. Spiel starten

**Endpunkt:** `POST /api/game/{gameId}/start`

**Beschreibung:** Startet ein Spiel und initialisiert Einstellungen.

**Request:**

```json
{
  "startscore": 301,
  "doubleout": true,
  "tripleout": false,
  "playerPositions": [1, 2, 3, 4]
}
```

**Validierung:**

- `startscore`: 101, 201, 301, 401, oder 501
- `doubleout`: Boolean (optional)
- `tripleout`: Boolean (optional)
- `playerPositions`: Array von Integer (optional, muss Spieleranzahl entsprechen)

**Logik (GameStartService):**

1. **Validierung:**

   - 2-10 Spieler erforderlich
   - `playerPositions` Länge = Spieleranzahl (falls angegeben)

2. **Spiel-Konfiguration:**

   - Setzt `status` auf `GameStatus::Started`
   - Setzt `startScore`, `doubleOut`, `tripleOut`

3. **Initialisierung:**
   - Erstellt erste `Round` (Runde 1)
   - Setzt jedem Spieler initialen Score (= startScore)
   - Ordnet Spieler-Positionen zu (via `GameSetupService`)

**Response:**

```json
{
  "gameId": 42,
  "status": "started",
  "startScore": 301,
  "doubleOut": true,
  "tripleOut": false,
  "round": 1,
  "players": [...]
}
```

---

#### 2. Wurf registrieren

**Endpunkt:** `POST /api/game/{gameId}/throw`

**Beschreibung:** Registriert einen Dartswurf und aktualisiert den Spielstand.

**Request:**

```json
{
  "playerId": 123,
  "value": 20,
  "isDouble": false,
  "isTriple": true,
  "isBust": false
}
```

**Validierung:**

- `playerId`: erforderlich, positiv
- `value`: 0-60
- `isDouble`, `isTriple`, `isBust`: Boolean (optional)

**Logik (GameThrowService):**

##### Schritt 1: Validierung

- Spieler muss im Spiel sein
- Maximal 3 Würfe pro Spieler pro Runde

##### Schritt 2: Punktberechnung

```php
$finalValue = $baseValue;
if ($isTriple) {
    $finalValue = $baseValue * 3;
} elseif ($isDouble) {
    $finalValue = $baseValue * 2;
}
$newScore = $currentScore - $finalValue;
```

##### Schritt 3: Bust-Prüfung

Ein Wurf ist **Bust**, wenn:

- Score < 0
- Score = 1 bei Double-Out oder Triple-Out Modus
- Score = 2 bei Triple-Out Modus
- Score = 0 **ohne** Double bei Double-Out Modus
- Score = 0 **ohne** Triple bei Triple-Out Modus

##### Schritt 4: Score Update

**Bei Bust:**

- Score wird auf Stand **vor der aktuellen Runde** zurückgesetzt
- Alle Würfe der Runde werden addiert und vom Score abgezogen

**Bei normalem Wurf:**

- Score wird aktualisiert: `newScore = currentScore - finalValue`

##### Schritt 5: Gewinn-Prüfung

Bei Score = 0:

- Spieler erhält Position (1., 2., 3., etc.)
- Erster Spieler wird `winner`
- Alle anderen Spieler: `isWinner = false`

##### Schritt 6: Rundenmanagement

Nach jedem Wurf:

1. Prüft, ob alle Spieler 3 Würfe haben
2. Falls ja: Startet neue Runde
3. Berechnet nächsten Spieler am Zug

##### Schritt 7: Spielende

Falls nur noch 1 aktiver Spieler:

- Setzt `status` auf `GameStatus::Finished`
- Setzt `finishedAt` Timestamp

**Response:**

```json
{
  "gameId": 42,
  "currentRound": 5,
  "currentPlayerId": 124,
  "players": [
    {
      "id": 123,
      "username": "player1",
      "score": 241,
      "position": null,
      "throws": [20, 60, 45]
    }
  ],
  "throws": [...]
}
```

---

#### 3. Wurf rückgängig machen

**Endpunkt:** `DELETE /api/game/{gameId}/throw`

**Beschreibung:** Macht den letzten Wurf rückgängig.

**Logik (GameThrowService):**

1. Findet letzten `RoundThrows` Eintrag
2. Stellt vorherigen Score wieder her
3. Löscht Wurf aus Datenbank
4. Aktualisiert Spiel-Status (falls nötig)

**Response:**

```json
{
  "gameId": 42,
  "currentRound": 5,
  "currentPlayerId": 123,
  "players": [...]
}
```

---

#### 4. Spielstand abrufen

**Endpunkt:** `GET /api/game/{gameId}`

**Beschreibung:** Gibt den aktuellen Spielstand zurück.

**Logik (GameService):**

1. Lädt `Game` Entity mit allen Relations
2. Erstellt `GameResponseDto`:
   - Spiel-Metadaten
   - Spieler-Liste mit aktuellem Score
   - Wurf-Historie
   - Aktueller Spieler

**Response:**

```json
{
  "gameId": 42,
  "status": "started",
  "startScore": 301,
  "doubleOut": true,
  "tripleOut": false,
  "currentRound": 5,
  "currentPlayerId": 123,
  "winner": null,
  "players": [
    {
      "id": 123,
      "username": "player1",
      "score": 241,
      "position": null,
      "isWinner": false,
      "throws": [20, 60, 45, 100, ...]
    }
  ],
  "throws": [
    {
      "playerId": 123,
      "round": 5,
      "throwNumber": 1,
      "value": 20,
      "score": 241,
      "isDouble": false,
      "isTriple": false,
      "isBust": false,
      "timestamp": "2025-12-08T10:30:00Z"
    }
  ]
}
```

---

#### 5. Spiel beenden

**Endpunkt:** `GET /api/game/{gameId}/finished`

**Beschreibung:** Schließt ein Spiel ab und generiert Statistiken.

**Logik (GameFinishService):**

1. Prüft, ob Spiel bereits beendet ist
2. Setzt `status` auf `GameStatus::Finished`
3. Setzt `finishedAt` Timestamp
4. Berechnet Spiel-Statistiken:
   - Gewinner
   - Finale Positionen
   - Anzahl Runden pro Spieler
   - Durchschnittswerte

**Response:**

```json
{
  "gameId": 42,
  "status": "finished",
  "date": "2025-12-08",
  "finishedAt": "2025-12-08T11:45:30Z",
  "winner": {
    "id": 123,
    "username": "player1"
  },
  "winnerRoundsPlayed": 8,
  "finishedPlayers": [
    {
      "id": 123,
      "username": "player1",
      "position": 1,
      "roundsPlayed": 8,
      "average": 37.5
    },
    {
      "id": 124,
      "username": "player2",
      "position": 2,
      "roundsPlayed": 10,
      "average": 30.1
    }
  ]
}
```

---

### Statistics

#### 1. Spiele-Übersicht

**Endpunkt:** `GET /api/games/overview`

**Beschreibung:** Listet abgeschlossene Spiele auf (paginiert).

**Query Parameter:**

```
?limit=20&offset=0
```

**Logik:**

1. Findet alle Spiele mit `status = GameStatus::Finished`
2. Sortiert nach `gameId` DESC (neueste zuerst)
3. Berechnet Statistiken für jedes Spiel

**Response:**

```json
{
  "limit": 20,
  "offset": 0,
  "total": 150,
  "items": [
    {
      "id": 42,
      "date": "2025-12-08T10:00:00Z",
      "finishedAt": "2025-12-08T11:45:30Z",
      "playersCount": 4,
      "winnerName": "player1",
      "winnerId": 123,
      "winnerRounds": 8
    }
  ]
}
```

---

#### 2. Spieler-Statistiken

**Endpunkt:** `GET /api/players/stats`

**Beschreibung:** Rangliste aller Spieler mit Statistiken.

**Query Parameter:**

```
?limit=20&offset=0&sort=average:desc
```

**Sort-Optionen:**

- `average:desc` - Nach Durchschnitt absteigend
- `average:asc` - Nach Durchschnitt aufsteigend
- `gamesplayed:desc` - Nach Anzahl Spiele absteigend
- `gamesplayed:asc` - Nach Anzahl Spiele aufsteigend

**Logik (GameStatisticsService):**

1. Aggregiert Daten aus `RoundThrows`
2. Berechnet für jeden Spieler:
   - Durchschnitt (Average): Summe aller Würfe / Anzahl Würfe
   - Anzahl Spiele
   - Anzahl Siege (1. Platz)
   - Checkout-Quote

**Response:**

```json
{
  "limit": 20,
  "offset": 0,
  "total": 50,
  "items": [
    {
      "playerId": 123,
      "username": "player1",
      "average": 42.5,
      "gamesPlayed": 25,
      "wins": 10,
      "checkoutRate": 35.5
    }
  ]
}
```

---

## Services & Business Logic

### GameStartService

**Verantwortung:** Spiel-Initialisierung

**Hauptmethode:** `start(Game $game, StartGameRequest $dto)`

**Prozess:**

1. Validiert Spieleranzahl (2-10)
2. Setzt Spiel-Einstellungen (startScore, doubleOut, tripleOut)
3. Ändert Status zu `GameStatus::Started`
4. Erstellt erste Runde
5. Initialisiert Spieler-Scores
6. Ordnet Spieler-Positionen zu

---

### GameThrowService

**Verantwortung:** Wurf-Verarbeitung und Spiel-Logik

**Hauptmethoden:**

- `recordThrow(Game $game, ThrowRequest $dto)` - Registriert Wurf
- `undoLastThrow(Game $game)` - Macht letzten Wurf rückgängig

**Kernlogik:**

1. **Validierung:** Spieler-Existenz, Wurf-Limit
2. **Punktberechnung:** Double/Triple Multiplikation
3. **Bust-Prüfung:** Score-Regeln für Game Modes
4. **Score-Update:** Bei Bust Rücksetzen, sonst Update
5. **Gewinn-Prüfung:** Score = 0 Check
6. **Rundenmanagement:** Auto-Rundenwechsel
7. **Spielende-Detection:** Letzter aktiver Spieler

---

### GameRoomService

**Verantwortung:** Spielraum-Erstellung und -Verwaltung

**Hauptmethoden:**

- `createGame()` - Erstellt leeres Spiel
- `createGameWithPreviousPlayers()` - Rematch-Funktionalität
- `findGameById()` - Spiel abrufen
- `getPlayersWithUserInfo()` - Spieler-Details

---

### GameFinishService

**Verantwortung:** Spielabschluss und Statistikberechnung

**Hauptmethoden:**

- `finishGame(Game $game)` - Schließt Spiel ab
- `getGameStats(Game $game)` - Berechnet Spiel-Statistiken

**Statistiken:**

- Gewinner
- Platzierungen
- Runden pro Spieler
- Durchschnittswerte
- Checkout-Statistiken

---

### GameStatisticsService

**Verantwortung:** Spieler-übergreifende Statistiken

**Hauptmethode:** `getPlayerStats()`

**Berechnet:**

- Gesamtdurchschnitt
- Anzahl Spiele
- Siege
- Win-Rate
- Checkout-Quote

---

### PlayerManagementService

**Verantwortung:** Spieler-Verwaltung in Spielen

**Hauptmethoden:**

- `addPlayer(int $gameId, int $playerId)` - Fügt Spieler hinzu
- `removePlayer(int $gameId, int $playerId)` - Entfernt Spieler
- `copyPlayers(int $fromGameId, int $toGameId)` - Kopiert Spieler für Rematch

---

### RematchService

**Verantwortung:** Rematch-Funktionalität

**Hauptmethode:** `createRematch(int $gameId)`

**Prozess:**

1. Findet Original-Spiel
2. Erstellt neues Spiel
3. Kopiert alle Spieler
4. Gibt neue Game-ID zurück

---

### SseStreamService

**Verantwortung:** Server-Sent Events für Echtzeit-Updates

**Hauptmethode:** `createPlayerStream(int $gameId)`

**Events:**

- `player-joined` - Spieler beigetreten
- `player-left` - Spieler verlassen
- `game-started` - Spiel gestartet
- `throw-recorded` - Wurf registriert
- `game-finished` - Spiel beendet

---

## Datenmodell

### Game

```php
- gameId: int (PK)
- type: int (nullable)
- winner: User (ManyToOne)
- date: DateTime
- startScore: int (default: 301)
- doubleOut: bool (default: false)
- tripleOut: bool (default: false)
- status: GameStatus (enum: Lobby, Started, Finished)
- round: int (nullable) - aktuelle Rundennummer
- finishedAt: DateTimeImmutable (nullable)
- gamePlayers: Collection<GamePlayers> (OneToMany)
- rounds: Collection<Round> (OneToMany)
- invitation: Invitation (OneToOne, nullable)
```

### User

```php
- id: int (PK)
- username: string
- email: string
- password: string (hashed)
- roles: array (default: ['ROLE_PLAYER'])
```

### GamePlayers

```php
- id: int (PK)
- game: Game (ManyToOne)
- player: User (ManyToOne)
- score: int (nullable) - aktueller Score
- position: int (nullable) - Finale Position (1, 2, 3, ...)
- isWinner: bool (default: false)
- playOrder: int (nullable) - Reihenfolge im Spiel
```

### Round

```php
- id: int (PK)
- game: Game (ManyToOne)
- roundNumber: int
- startedAt: DateTime
- roundThrows: Collection<RoundThrows> (OneToMany)
```

### RoundThrows

```php
- id: int (PK)
- game: Game (ManyToOne)
- round: Round (ManyToOne)
- player: User (ManyToOne)
- throwNumber: int (1, 2, oder 3)
- value: int - Wurf-Wert (inkl. Double/Triple)
- score: int - Score nach diesem Wurf
- isDouble: bool
- isTriple: bool
- isBust: bool
- timestamp: DateTime
```

### Invitation

```php
- id: int (PK)
- uuid: Uuid
- gameId: int
```

### PlayerStats (optional, für aggregierte Stats)

```php
- id: int (PK)
- player: User (OneToOne)
- average: float
- gamesPlayed: int
- wins: int
- checkoutRate: float
```

---

## Workflow & Spielablauf

### 1. Spielerstellung

```
POST /api/room/create
  → GameRoomService.createGame()
  → Erstellt Game mit status=Lobby
  → Gibt gameId zurück
```

### 2. Spieler beitreten

```
Option A: Direkt
  → PlayerManagementService.addPlayer(gameId, playerId)

Option B: Via Einladung
  → POST /api/invite/create/{gameId}
  → Generiert UUID und Link
  → GET /api/invite/join/{uuid}
  → Speichert in Session
  → Login → login/success verarbeitet Einladung
```

### 3. SSE Stream

```
GET /api/room/{id}/stream
  → Client öffnet EventSource
  → Server sendet Updates bei Änderungen
  → Player beitreten/verlassen
  → Spiel startet
```

### 4. Spiel starten

```
POST /api/game/{gameId}/start
{
  "startscore": 301,
  "doubleout": true,
  "playerPositions": [2, 1, 3]
}
  → GameStartService.start()
  → Setzt status=Started
  → Initialisiert Runde 1
  → Setzt Spieler-Scores auf 301
  → Ordnet Reihenfolge zu
```

### 5. Würfe registrieren

```
Loop bis Spiel beendet:
  POST /api/game/{gameId}/throw
  {
    "playerId": 123,
    "value": 20,
    "isTriple": true
  }
    → GameThrowService.recordThrow()
    → Berechnet finalValue = 20 * 3 = 60
    → newScore = currentScore - 60
    → Prüft Bust-Regeln
    → Aktualisiert Score
    → Prüft Gewinn (Score = 0)
    → Falls alle 3 Würfe → Nächste Runde
    → Falls nur 1 aktiver Spieler → Spiel beenden
```

### 6. Spielende

```
Automatisch bei letztem aktiven Spieler:
  → Setzt status=Finished
  → Setzt finishedAt

Oder manuell:
  GET /api/game/{gameId}/finished
    → GameFinishService.finishGame()
    → Berechnet finale Statistiken
    → Gibt GameFinishDto zurück
```

### 7. Rematch

```
POST /api/room/{gameId}/rematch
  → RematchService.createRematch()
  → Erstellt neues Spiel
  → Kopiert alle Spieler
  → Zurück zu Schritt 3
```

---

## Wichtige Hinweise

### Bust-Regeln (Double-Out/Triple-Out)

**Standard-Modus:**

- Score < 0: Bust
- Score = 0: Gewonnen

**Double-Out:**

- Score < 0: Bust
- Score = 1: Bust (nicht mit Double zu schaffen)
- Score = 0 ohne Double: Bust
- Score = 0 mit Double: Gewonnen

**Triple-Out:**

- Score < 0: Bust
- Score = 1 oder 2: Bust (nicht mit Triple zu schaffen)
- Score = 0 ohne Triple: Bust
- Score = 0 mit Triple: Gewonnen

### Score-Rücksetzung bei Bust

Bei einem Bust wird der Score auf den Stand **vor der aktuellen Runde** zurückgesetzt:

```php
// Beispiel:
// Score vor Runde: 100
// Wurf 1: 20 (Score: 80)
// Wurf 2: 60 (Score: 20)
// Wurf 3: 25 (Bust, da Score < 0)

// Resultat: Score = 100 (zurückgesetzt)
```

### Rundenmanagement

- Jeder Spieler hat **3 Würfe pro Runde**
- Reihenfolge wird durch `playOrder` in `GamePlayers` bestimmt
- Nach allen Würfen: Automatischer Rundenwechsel
- Neue Runde wird automatisch erstellt

### Positionierung

- Position wird vergeben, wenn Score = 0 erreicht wird
- 1. Spieler: Position 1, isWinner = true
- 2. Spieler: Position 2, isWinner = false
- etc.

### Echtzeit-Updates

Das Backend nutzt **Server-Sent Events (SSE)** für Echtzeit-Updates:

- Clients öffnen Stream via `GET /api/room/{id}/stream`
- Server pushed Events bei Änderungen
- Automatische Reconnect-Logik im Client empfohlen

---

## Error Handling

### Standardisierte Error Responses

```json
{
  "error": "Fehlermeldung",
  "code": "ERROR_CODE"
}
```

**Häufige Fehler:**

- `404 Not Found`: Game/Player nicht gefunden
- `400 Bad Request`: Validierungsfehler, ungültige Parameter
- `401 Unauthorized`: Nicht authentifiziert
- `403 Forbidden`: Keine Berechtigung
- `500 Internal Server Error`: Server-Fehler

### Validierungsfehler

```json
{
  "success": false,
  "message": "Validierung fehlgeschlagen",
  "errors": {
    "fieldName": ["Fehlermeldung 1", "Fehlermeldung 2"]
  }
}
```

---

## Testing

Das Backend enthält Tests in `/tests`:

- **Controller Tests**: Testen HTTP-Endpunkte
- **Service Tests**: Testen Business Logic
- **Integration Tests**: End-to-End Workflows

```bash
# Tests ausführen
docker-compose exec php bin/phpunit
```

---

## Deployment

### Docker Setup

```bash
# Container starten
docker-compose up -d

# Datenbank migrieren
docker-compose exec php bin/console doctrine:migrations:migrate

# Cache leeren
docker-compose exec php bin/console cache:clear
```

### Umgebungsvariablen

Siehe `.env` und `.env.dev`:

- `DATABASE_URL`: Datenbank-Verbindung
- `APP_ENV`: prod/dev
- `APP_SECRET`: Symfony Secret

---

## Weitere Ressourcen

- [Symfony Documentation](https://symfony.com/doc/current/index.html)
- [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html)
- [REST API Best Practices](https://restfulapi.net/)

---

**Dokumentation erstellt am:** 2025-12-08  
**Version:** 1.0  
**Backend Framework:** Symfony 7
