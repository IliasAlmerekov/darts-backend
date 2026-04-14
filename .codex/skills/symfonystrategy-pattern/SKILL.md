---
name: symfony-strategy-pattern
allowed-tools:
  - Read
  - Glob
  - Grep
description: Use when a Symfony backend change needs runtime-selectable behavior with multiple implementations, and you need to decide whether a tagged strategy pattern is actually the right fit.
---

# Symfony Strategy Pattern

Use this as a reference skill for Symfony strategy selection.

This skill does not replace the local workflow:

- use `brainstorming-feature` for design
- use `planning-feature` for execution planning
- use `subagent-development` for implementation

This skill only helps answer one narrow question:

- should this behavior use a strategy pattern in this repository
- and if yes, should it use tagged iteration or tagged lookup

## Use When

Use this skill when all of the following are true:

- one capability has multiple interchangeable implementations
- selection happens by runtime input, domain state, or format key
- you want to avoid growing `if/elseif`, `match`, or controller branching
- the behavior belongs in services, not in entities or controllers

Typical cases:

- export formats
- payment or provider adapters
- channel-specific notification delivery
- rule engines with explicit strategy keys
- policy-like behavior where each implementation has the same contract

## Do Not Use When

Do not use strategy pattern when:

- there are only two trivial branches and they are unlikely to grow
- the variation is just data, config, or mapping, not behavior
- one private method split would solve the problem
- the behavior belongs in a repository query or DTO validation rule
- the design would create artificial abstractions only to look extensible

If the main reason is “future-proofing”, that is usually not enough.

## First Decision

Before using the pattern, answer:

1. What is varying: behavior, provider, format, or policy?
2. Is the selection key stable and explicit?
3. Is the ownership layer a service boundary?
4. Will a new implementation be added without modifying the caller?
5. Is this real polymorphism, or just branching around small data differences?

If you cannot answer these clearly, do not jump to a strategy abstraction.

## Pattern Choices

### `AutowireIterator` + `supports()`

Prefer this when:

- the selection rule is dynamic
- more than one strategy may theoretically match
- the caller should not know service ids or keys

Trade-off:

- simpler call site
- but selection logic is runtime-scanned and can hide ambiguity if multiple strategies support the same input

### `TaggedLocator` + explicit key

Prefer this when:

- the selection key is stable, explicit, and unique
- formats, providers, or modes map cleanly to one implementation
- you want deterministic lookup and clearer failure for unsupported keys

Trade-off:

- clearer and usually better when the key already exists
- but requires an explicit indexing method such as `getFormat()` or `getKey()`

## Repository Fit

For this project, keep these boundaries:

- controllers stay thin
- strategies belong in services or dedicated application/domain service layers
- repositories should not become strategy registries for non-persistence logic
- DTO validation should stay DTO-focused, not become a strategy dumping ground
- response contract changes still need serializer and Nelmio review

If a strategy affects persistence, transaction scope, or API contract, route that
through the normal design and planning workflow first.

## Design Checklist

When the pattern is appropriate, make these decisions explicit:

- strategy interface contract
- caller ownership
- strategy selection mechanism
- unsupported-key behavior
- duplicate-match behavior
- fallback behavior, if any
- observability or debugging expectations
- tests for selection and negative paths

## Common Failure Modes

- strategy introduced where a plain service split was enough
- multiple strategies accidentally support the same input
- fallback strategy silently catches unsupported cases and hides bugs
- caller still knows too much about specific implementations
- controller becomes the selector instead of a service
- strategy interface grows unrelated methods and becomes a grab bag
- verification covers only happy-path strategy selection

## Testing Expectations

At minimum, test:

- correct strategy selected for valid input
- unsupported input fails clearly
- duplicate or ambiguous selection is impossible or explicitly handled
- negative path behavior for each important strategy
- caller behavior stays stable when a new implementation is added

## Project Verification

If code changes are made from a design using this pattern, verification still
follows repository policy. Use the normal root-level Docker-based flow:

```bash
rtk docker compose up -d php mysql
rtk docker compose exec -T php bash -lc 'cd /var/www/html && mkdir -p build'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpcs'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php vendor/bin/psalm --show-info=false --report=build/psalm-quality-report.json'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php bin/console lint:yaml -v --ansi --env=test config'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console cache:clear --env=test'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:database:create --env=test --if-not-exists'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 bin/console doctrine:migrations:migrate --env=test --no-interaction'
rtk docker compose exec -T php bash -lc 'cd /var/www/html && php -d memory_limit=-1 vendor/bin/phpunit --coverage-text --exclude-group ignore --coverage-clover build/phpunit.coverage.xml --coverage-cobertura build/phpunit.coverage.cobertura.xml --log-junit build/phpunit.xml'
```

## References

- `reference.md`
