---
status: accepted
---

# Layered architecture with one service per use case

The backend had no written architecture. The code had settled on one shape anyway:
controllers bind requests into DTOs, one service per use case runs the business rules,
and Doctrine repositories and entities hold the data. Entities carry almost no behaviour,
and the statistics aggregates are SQL in the repositories. Without a record, every new
change had to guess whether that shape was intended or an accident to fix.

We keep this shape and describe it in [`docs/architecture.md`](../architecture.md). Each
service and repository stays behind an interface, because the classes are `final` and the
unit tests mock their dependencies. Controllers may read repositories directly; writes go
through a use-case service, which also owns the transaction. Entities enforce invariants
over their own state only.

## Considered Options

- MVC. Rejected as a label: the API renders JSON through DTOs and serializer groups, not
  templates, so there is no view layer to speak of.
- Rich domain model, with game rules moved into `Game`, `Round`, and `RoundThrows`.
  Rejected: it means rewriting the game services and their tests for no change in
  behaviour, and the throw flow spans several aggregates anyway.
- Hexagonal or clean architecture, with a domain free of Doctrine and ports for every
  adapter. Rejected: the API has one database and one client, so the ports would add a
  layer of interfaces that no second adapter ever uses. `AGENTS.md` asks for the least
  new code.
- An automated layer check such as deptrac. Rejected for now: it adds a dependency and a
  config to keep in step, and code review catches the same mistakes at this size.

## Consequences

- New code follows the rules in `docs/architecture.md`; a reviewer cites that file.
- Three controllers that open their own write transactions are known debt, tracked in #195.
- If the rules start to slip in review, revisit the automated check.
