# Changelog

Alle wichtigen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
und dieses Projekt hält sich an [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - 2026-02-03

### Hinzugefügt
- **Tablet-Spiel**: Neuer Admin-Endpunkt `GET /api/tablet/game` liefert das Tablet-Spiel (`lobby` oder `started`) und die ID des zuletzt beendeten Spiels nach `finishedAt`. Beides ist `null`, wenn es keins gibt (#132)
- **CI**: Dependabot öffnet wöchentlich Update-PRs für Composer, GitHub Actions und die Docker-Basisimages. Minor- und Patch-Updates von Composer kommen gebündelt in einem PR, Actions-Updates ebenso. `symfony/*` bekommt nur Patch-Updates, das `php`-Image bleibt auf 8.4 (#138)
- **Agenten**: `AGENTS.md` hat einen Abschnitt `## Review guidelines` mit den Schweregraden P0 bis P2 und den Prüfungen, die kein Werkzeug übernimmt. Das neue `SECURITY.md` erklärt, wie man Schwachstellen privat meldet, und enthält das Bedrohungsmodell für Security-Reviews (#136)
- **Spielerprofil**: Neuer Endpunkt `GET /api/player/profile` liefert dem angemeldeten Spieler seine Statistik und seine 10 letzten beendeten Spiele mit Platzierung, Rundendurchschnitt und allen Teilnehmern (#115)
- **CSRF-Schutz**: Neue Route `/api/csrf` für öffentlichen Zugriff hinzugefügt. CSRF-Tokens werden nun in Registrierungs- und Login-Formularen verwendet, um Anfragen vor Manipulation zu schützen.
- **GameAccessService**: Neuer Service zur Durchsetzung von Spiel-Autorisierungsregeln. Überprüft, ob Benutzer authentifiziert, Administrator oder Spieler in einem Spiel sind.
- **SecurityAccessDeniedException**: Neue Ausnahme für Zugriffsverweigerung, die bei fehlenden Berechtigungen ausgelöst wird.
- **ErrorCode**: Neuer Fehlercode `SecurityAccessDenied` für API-Fehlerbehandlung.
- **CSRF-Tokens-Endpunkt**: Neuer Endpunkt `/api/csrf` in `SecurityController`, der CSRF-Tokens für Authentifizierungsflüsse bereitstellt.

### Geändert
- **Registrierung**: `POST /api/register` nimmt Passwörter ab 6 statt ab 8 Zeichen an; ein kürzeres beantwortet die API mit 400 und `errors.plainPassword`. Die OpenAPI-Doku nennt `minLength: 6` (#130)
- **API-Doku**: Das OpenAPI-Schema `CurrentGameResponseDto` von `GET /api/player/current-game` markiert `gameId` und `status` als Pflichtfelder und beschränkt `status` auf `lobby`, `started` oder `null`; die Antwort selbst bleibt gleich (#123)
- **Agenten**: `AGENTS.md` schätzt jede Aufgabe vor der ersten Änderung ein. Eine kleine Aufgabe geht direkt zu Branch, einem fehlschlagenden Test und der Änderung; eine große läuft über `/grill-with-docs`, einen Plan aus Teilaufgaben mit Akzeptanzkriterien, den Skill `tdd` pro Teilaufgabe und `code-review` gegen `main`. `code-review` behandelt die Scope-Regeln als Repo-Standard, und das Verbot von Agenten-Hinweisen hat Vorrang vor dem Hinweis des Skills `triage` (#124)
- **CI**: Die GitLab-Pipeline läuft nur noch für Merge Requests, `main` und Tags, prüft das Schema mit `doctrine:schema:validate` und bricht überholte Pipelines ab (#120)
- **Agenten**: `AGENTS.md` beschreibt den Ablauf ohne Worktrees, einen schnellen Testlauf, den Fehlervertrag, Migrationen, Test-Helfer und eine Scope-Regel (#120)
- **GameLifecycleController**: Methode `finished` in zwei separate Methoden aufgeteilt: `finish` (POST zum Beenden des Spiels) und `finished` (GET zum Anzeigen der Endergebnisse ohne Statusänderung).
- **InvitationController**: `processInvitation` nun explizit auf POST-Methoden beschränkt.
- **RegistrationController**: `_csrf_token` als erforderliches Feld in der Registrierungs-API hinzugefügt.
- **SecurityController**: Login-Antwort enthält nun `email` und `username` zusätzlich zu `id`. CSRF-Tokens werden in Login- und Registrierungsformularen verwendet.
- **RegistrationFormType**: E-Mail-Validierung hinzugefügt, um gültige E-Mail-Adressen sicherzustellen.
- **SecurityService**: Antwort bei erfolgreichem Login enthält nun vollständige Benutzerdetails (`email`, `username`).
- **Verschiedene Services**: Integration von `GameAccessService` in `GameAbortService`, `GameFinishService`, `GameRoomService`, `GameSettingsService`, `GameStartService`, `GameThrowService`, `RematchService` und `InvitationService` für Zugriffskontrollen vor Spielaktionen.

### Behoben
- **Spielraum**: `POST /api/room/create` ignoriert `previousGameId`. Die neue Lobby bekommt die Spieler aus `playerIds` ohne `excludePlayerIds`, statt sie nach einem vom Client genannten alten Spiel zu filtern und dessen Reihenfolge zu übernehmen. Die API-Doku markiert das Feld als veraltet (#133)
- **Spiel**: Undo eines beendeten Spiels, Reopen, Rematch und Rematch-Start wirken nur noch auf das neueste Spiel und antworten sonst mit 409 `GAME_NOT_NEWEST`, damit ein altes Spiel das laufende Spiel nicht abbricht. Rematch und Rematch-Start nehmen ein beendetes oder abgebrochenes Spiel an (ADR 0005) (#131)
- **Spiel**: Wurf und Undo laden das Spiel nach der Sperre neu. Ein parallel committeter Siegwurf wird so nicht mehr übersehen: Ein Undo öffnet das Spiel korrekt wieder statt nur den Siegwurf zu löschen, und ein weiterer Wurf in das beendete Spiel wird abgelehnt (#131)
- **Sicherheitslücken**: Zugriffsprüfungen hinzugefügt, um unbefugten Zugriff auf Spielaktionen zu verhindern (z. B. nur Spieler oder Administratoren können Spiele ändern).

### Tests
- **Aktualisierungen**: Alle Tests (z. B. `RegistrationControllerTest`, `SecurityControllerTest`, `GameFinishServiceTest` usw.) angepasst, um mit dem neuen `GameAccessService` zu arbeiten. Mocks für Zugriffskontrollen hinzugefügt.

### Sicherheit
- **Verbesserte Autorisierung**: Spielaktionen sind nun durch rollenbasierte Zugriffsprüfungen geschützt, um Missbrauch zu verhindern.
- **CSRF-Schutz**: Schutz vor Cross-Site Request Forgery-Angriffen durch Token-Validierung.

Diese Änderungen stärken die Sicherheit des Darts-Backends, indem sie Autorisierung und Anfrageschutz implementieren.