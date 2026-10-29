# Architecture

The backend is a layered Symfony application. It is not MVC: the API answers with JSON
and has no templates. A request that writes passes through three layers, and each layer
calls only the one below it. A request that only reads may skip the service and go from
the controller to the repository.

```text
write:  Controller  ->  use-case Service  ->  Repository / Entity (Doctrine)
read:   Controller  ->  Repository
```

[ADR 0008](adr/0008-layered-architecture.md) records why the layers stay as they are and
why the project does not move to hexagonal or a rich domain model.

## Layers

| Layer | Directory | Owns |
| --- | --- | --- |
| Controller | `app/src/Controller/` | Routing, request binding into DTOs, access checks, picking the use case, building the response DTO. |
| Service | `app/src/Service/<Area>/` | One use case per service: start a game, record a throw, accept an invitation. Business rules and the write transaction live here. |
| Repository | `app/src/Repository/` | Queries. Reads by key, locking reads (`...ForUpdate`), and the statistics aggregates. |
| Entity | `app/src/Entity/` | Doctrine mapping and the invariants of its own state. |

Around the layers:

- `app/src/Dto/` holds request and response DTOs. Controllers return a response DTO, and
  `ApiViewSubscriber` serializes it with the serializer groups. This pair plus
  `GameStateViewBuilder` is what MVC would call the view.
- `app/src/Exception/` holds the API errors, rendered by `ErrorController`
  ([ADR 0006](adr/0006-one-error-body.md)).
- `app/src/EventSubscriber/` and `app/src/Security/` hold cross-cutting HTTP concerns:
  origin check, remembered login, rate limits, authentication responses.

## Rules

**Every service and repository has an interface.** Classes are `final`, and PHPUnit
cannot mock a final class. Unit tests mock the interface so they run without the real
database. Callers depend on the interface, not on the class.

**A controller may read a repository directly; writes go through a service.** A list or
a detail page that only reads data calls the repository from the controller, as
`GameStatsController` does. Anything that changes state calls a use-case service.

**The use-case service owns the write transaction.** It opens the transaction through
`RetryingTransactionInterface` and calls `flush()`. Three controllers still open
transactions themselves; #195 moves them into their services.

**Statistics aggregates live in repository SQL.** Averages, totals, and player rankings
are SQL in `PlayerStatisticsRepository` and its neighbours, because the database computes them
faster than PHP. The measurements are in `docs/benchmarks/BE-701` to `BE-704`. Game rules
such as checkout, bust, and turn order stay in PHP services, not in SQL.

**An entity changes only its own state.** An entity method may enforce an invariant over
its own fields and its owned collections, such as
`Game::takeBackPlacementsFromSeatsWithScoreLeft()`. It calls no service, repository, or
entity manager. A flow that touches several aggregates or the database is a service.

## Where new code goes

- New endpoint: a controller action, a request DTO, a response DTO, and a use-case
  service if the endpoint writes.
- New business rule: the service of the use case it belongs to. If the rule checks only
  one entity's own fields, a method on that entity.
- New query: a method on the repository and its interface.

Nothing checks these rules automatically. Code review checks them.
