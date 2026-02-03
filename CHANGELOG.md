# Changelog

Alle wichtigen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
und dieses Projekt hält sich an [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - 2026-02-03

### Hinzugefügt
- **CSRF-Schutz**: Neue Route `/api/csrf` für öffentlichen Zugriff hinzugefügt. CSRF-Tokens werden nun in Registrierungs- und Login-Formularen verwendet, um Anfragen vor Manipulation zu schützen.
- **GameAccessService**: Neuer Service zur Durchsetzung von Spiel-Autorisierungsregeln. Überprüft, ob Benutzer authentifiziert, Administrator oder Spieler in einem Spiel sind.
- **SecurityAccessDeniedException**: Neue Ausnahme für Zugriffsverweigerung, die bei fehlenden Berechtigungen ausgelöst wird.
- **ErrorCode**: Neuer Fehlercode `SecurityAccessDenied` für API-Fehlerbehandlung.
- **CSRF-Tokens-Endpunkt**: Neuer Endpunkt `/api/csrf` in `SecurityController`, der CSRF-Tokens für Authentifizierungsflüsse bereitstellt.

### Geändert
- **GameLifecycleController**: Methode `finished` in zwei separate Methoden aufgeteilt: `finish` (POST zum Beenden des Spiels) und `finished` (GET zum Anzeigen der Endergebnisse ohne Statusänderung).
- **InvitationController**: `processInvitation` nun explizit auf POST-Methoden beschränkt.
- **RegistrationController**: `_csrf_token` als erforderliches Feld in der Registrierungs-API hinzugefügt.
- **SecurityController**: Login-Antwort enthält nun `email` und `username` zusätzlich zu `id`. CSRF-Tokens werden in Login- und Registrierungsformularen verwendet.
- **RegistrationFormType**: E-Mail-Validierung hinzugefügt, um gültige E-Mail-Adressen sicherzustellen.
- **SecurityService**: Antwort bei erfolgreichem Login enthält nun vollständige Benutzerdetails (`email`, `username`).
- **Verschiedene Services**: Integration von `GameAccessService` in `GameAbortService`, `GameFinishService`, `GameRoomService`, `GameSettingsService`, `GameStartService`, `GameThrowService`, `RematchService` und `InvitationService` für Zugriffskontrollen vor Spielaktionen.

### Behoben
- **Sicherheitslücken**: Zugriffsprüfungen hinzugefügt, um unbefugten Zugriff auf Spielaktionen zu verhindern (z. B. nur Spieler oder Administratoren können Spiele ändern).

### Tests
- **Aktualisierungen**: Alle Tests (z. B. `RegistrationControllerTest`, `SecurityControllerTest`, `GameFinishServiceTest` usw.) angepasst, um mit dem neuen `GameAccessService` zu arbeiten. Mocks für Zugriffskontrollen hinzugefügt.

### Sicherheit
- **Verbesserte Autorisierung**: Spielaktionen sind nun durch rollenbasierte Zugriffsprüfungen geschützt, um Missbrauch zu verhindern.
- **CSRF-Schutz**: Schutz vor Cross-Site Request Forgery-Angriffen durch Token-Validierung.

Diese Änderungen stärken die Sicherheit des Darts-Backends, indem sie Autorisierung und Anfrageschutz implementieren.