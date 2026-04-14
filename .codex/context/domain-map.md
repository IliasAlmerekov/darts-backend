# Domain Map

Last updated: 2026-04-14

## Main Product Area

The core domain is a darts game backend with gameplay lifecycle, throws,
statistics, invitations, registration, and security/access control.

## Primary Game Flows

### Game Lifecycle

- HTTP entry: `GameLifecycleController`
- Main service cluster:
  - `GameStartService`
  - `GameFinishService`
  - `GameAbortService`
  - `GameReopenService`
  - `RematchService`
  - `RematchStartService`
  - `GameSetupService`
  - `GameStateVersionService`
- Likely persistence touch points:
  - `GameRepository`
  - `RoundRepository`
  - `GamePlayersRepository`
  - `RoundThrowsRepository`

### Game Room And Setup

- HTTP entry: `GameRoomController`
- Main services:
  - `GameRoomService`
  - `PlayerManagementService`
  - `GuestPlayerService`
  - `GameSettingsService`

### Throws And Scoreboard

- HTTP entry: `GameThrowController`
- Main services:
  - `GameThrowService`
  - `GameDeltaService`
- DTO cluster includes:
  - `ThrowRequest`
  - `ThrowResponseDto`
  - `ThrowDeltaDto`
  - `ThrowAckDto`
  - `UndoAckDto`
  - scoreboard delta DTOs

### Stats

- HTTP entry: `GameStatsController`
- Main service: `GameStatisticsService`
- Persistence: `PlayerStatsRepository`

### Invitations And Registration

- HTTP entries:
  - `InvitationController`
  - `RegistrationController`
- Main services:
  - `InvitationService`
  - `RegistrationService`

### Security And Access

- HTTP entry: `SecurityController`
- Main services:
  - `SecurityService`
  - `GameAccessService`
- Error handling and fallback controller:
  - `Security/ErrorController`

## API And Response Shaping

- Controllers rely on DTOs rather than returning entities directly
- `ApiViewSubscriber` is an important response-shaping integration point
- `ApiResponse` attribute influences serializer or response behavior
- If request or response contracts change, serializer and Nelmio implications must be checked

## Error Model

- Domain and request failures are mapped through typed exceptions under `app/src/Exception`
- `ApiHttpException` descendants appear to be the main contract boundary for API-visible failures

## Testing Shape

- Controllers have matching test classes under `app/tests/Controller`
- Service and repository clusters also have dedicated test directories
- For non-trivial changes, likely regression surfaces are:
  - matching controller tests
  - touched service tests
  - touched repository tests
  - serializer or event subscriber tests where response behavior changes

## Current Architectural Bias

- Thin controllers
- DTO-based input validation
- service-layer business logic
- repository-owned query logic
- Docker-based verification from repository root
