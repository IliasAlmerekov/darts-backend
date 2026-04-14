# Project Map

Last updated: 2026-04-14

## Repository Layout

- `AGENTS.md`: canonical repository policy for runtime, verification, and reporting
- `.codex/`: local agent workflow, skills, agents, and persistent context
- `.claude/`: mirrored Claude workflow layer for the same repository process
- `app/`: Symfony application root
- `docker-compose.yaml`: default local runtime
- `etc/docker/nginx/nginx.conf`: nginx config for local stack
- `docs/superpowers/`: historical brainstorming and planning artifacts from the previous workflow

## Symfony App Layout

- `app/src/Controller`: HTTP entry points
  - current controllers:
    - `GameLifecycleController`
    - `GameRoomController`
    - `GameStatsController`
    - `GameThrowController`
    - `InvitationController`
    - `RegistrationController`
    - `SecurityController`
- `app/src/Dto`: request and response DTOs for API boundaries
- `app/src/Service`: business logic by domain
  - strong clusters:
    - `Service/Game`
    - `Service/Invitation`
    - `Service/Registration`
    - `Service/Security`
    - `Service/Sse`
    - `Service/Player`
- `app/src/Repository`: Doctrine repositories and query logic
- `app/src/Entity`: Doctrine entities
- `app/src/EventSubscriber`: framework integration, especially response shaping
- `app/src/Security`: security-specific controllers and services
- `app/src/Exception`: API and domain exception mapping
- `app/src/Http`: HTTP-specific helpers and attributes
- `app/src/Infrastructure/Doctrine`: Doctrine integration details
- `app/src/Enum`, `app/src/Util`, `app/src/Form`: support layers

## Test Layout

- `app/tests/Controller`: endpoint and functional tests
- `app/tests/Service`: service-level tests
- `app/tests/Repository`: repository and query behavior tests
- `app/tests/Dto`, `app/tests/EventSubscriber`, `app/tests/Security`, `app/tests/Util`: targeted support tests

## Runtime And Verification

- Default execution path: root `docker compose` stack, not nested compose files
- Main services: `php`, `nginx`, `mysql`, `phpmyadmin`
- Required code verification after code changes:
  - PHPCS
  - Psalm
  - CI-equivalent Symfony and PHPUnit flow
- All shell commands must go through `rtk`

## Local Agent Workflow

- Entry workflow for non-trivial work:
  - `brainstorming-feature`
  - `planning-feature`
  - `subagent-development`
- Primary authored workflow layer: `.codex/`
- Mirrored runtime layer: `.claude/`
- Single persistent context source: `.codex/context/`
- Lead agent: `lead_orchestrator`
- Default execution pipeline:
  - `researcher`
  - `coder`
  - `reviewer`
  - `tester`
  - `security`
  - `architect`
  - `explorer`

## Structural Notes

- Controllers are expected to stay thin
- DTOs and validators own input validation boundaries
- Services own business behavior
- Repositories own persistence and query behavior
- Response shaping is influenced by `ApiViewSubscriber` and `ApiResponse`
- Schema changes require Doctrine migrations
