# Engineering Principles

This file is a decision framework for applying design principles in this
Symfony backend. It is not a generic manifesto and not a command to force
patterns everywhere.

## Purpose

Use these principles to decide:

- when a simple implementation is correct
- when abstraction is justified
- when a pattern would be over-engineering
- where behavior should live
- when a fix is at the wrong layer

Prefer the simplest correct design that preserves invariants, keeps contracts
stable, and remains easy to test and evolve.

## Core Decision Rule

Do not apply SOLID, DRY, KISS, YAGNI, or design patterns mechanically.

Choose the smallest design that is justified by:

- current behavior
- real change pressure
- clear ownership boundaries
- regression risk
- testability
- expected evolution of the code

If a pattern does not improve correctness, maintainability, testability, or
change isolation in this codebase, do not add it.

## Default Stance

Start with explicit code in the correct layer.

- Prefer a focused service over a new pattern for one stable use case
- Prefer a direct dependency over a factory, registry, or strategy when there is only one real implementation
- Prefer a simple repository method over a query abstraction when the query surface is still small
- Prefer composition over inheritance
- Prefer clear branching over premature indirection

Abstraction must earn its cost.

## Applying Principles Selectively

### KISS

Use KISS by default.

- Keep controllers thin and explicit
- Keep services readable and traceable
- Avoid abstractions that make debugging or onboarding harder
- If the simple version is correct and likely to stay correct, keep it

### YAGNI

Do not add extension points for imagined futures.

- No interfaces only for test doubles when the existing boundary already allows testing
- No strategy, factory, or policy objects for a single stable variant
- No generic helper that merges unrelated use cases
- No schema or API flexibility without a real caller need

### DRY

Apply DRY to duplicated behavior, rules, and invariants, not to similar-looking code.

- Extract when the same rule would otherwise need to be changed in multiple places
- Do not merge code paths that look similar but represent different domain meaning
- Do not centralize logic if it creates a god-service or obscures ownership

### SOLID

Use SOLID as a pressure gauge, not as ceremony.

- `SRP`: each controller, service, repository, or DTO should have one clear reason to change
- `OCP`: prefer extension points only when multiple real variants exist or are imminent
- `LSP`: implementations must honor interface contracts without hidden preconditions
- `ISP`: prefer narrow interfaces over broad “do everything” service contracts
- `DIP`: depend on explicit boundaries, not the container or concrete infrastructure where a stable boundary exists

## Pattern Fit Signals

Introduce a pattern only when the code shows real signals for it.

### Strategy Or Policy

Use when:

- there are already multiple behavior variants
- variant selection is business-relevant and explicit
- conditional branching is starting to sprawl across the same decision axis
- tests would become clearer by isolating variant behavior

Do not use when:

- there is only one real behavior
- the second variant is speculative
- a private method or focused service keeps the logic clearer

### Interface Extraction

Use when:

- multiple implementations exist or are likely in the near term
- the boundary is meaningful to the domain or architecture
- swapping implementations changes infrastructure, not business meaning

Do not use when:

- the interface only mirrors one class
- the only motivation is “SOLID says so”
- the abstraction adds navigation and ceremony without reducing coupling

### Dedicated Service Extraction

Use when:

- a controller or service is accumulating multiple responsibilities
- logic needs isolated tests
- the behavior has a coherent use-case boundary
- reuse or orchestration pressure is emerging

Do not use when:

- the extracted service would only proxy one method call
- the new class hides a tiny amount of obvious logic without clarifying ownership

### Repository Or Persistence Abstraction

Use when:

- query logic is non-trivial or reused
- persistence concerns would otherwise leak into services or controllers
- performance-sensitive query behavior needs an explicit home

Do not use when:

- the abstraction hides a simple one-off ORM operation
- the query surface does not yet justify another layer

## Symfony Backend Heuristics

In this project:

- Controllers should map input, delegate, and shape HTTP response behavior
- DTOs and validators own request validation rules
- Services own use-case and domain orchestration logic
- Repositories own persistence and query behavior
- Entities should not accumulate non-trivial business orchestration
- Serializer and Nelmio implications matter when response shape changes
- Migrations are mandatory when persistence shape changes

When deciding where code belongs, choose the layer that owns the invariant, not
the layer where the symptom first appears.

## Wrong-Layer Smells

Be suspicious when a change:

- fixes a business bug in the controller
- adds null checks where invalid state should be impossible
- catches and hides exceptions instead of addressing the invalid state source
- duplicates validation across controller, DTO, and service without a clear reason
- introduces an interface, factory, or strategy with only one implementation and no real change pressure
- moves persistence logic into services because it was faster

These are often signs of symptom-fixing or accidental architecture drift.

## Bugfix Discipline

For bugs and regressions:

- identify the violated invariant first
- trace the root cause through the real ownership boundary
- fix the cause at the correct layer
- add tests that prove the defect class is actually covered

Do not accept a fix just because the symptom disappears in one path.

## Questions Before Adding Abstraction

Before introducing a new pattern or layer, answer:

1. What concrete pain does this remove in the current code?
2. What second real use case or variant justifies the abstraction?
3. Does this make ownership clearer or blurrier?
4. Does it improve testability or just add ceremony?
5. Would a new team member understand this faster or slower?
6. What happens if the future variation never arrives?

If these answers are weak, keep the simpler design.
