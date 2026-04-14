# Context Layer

This folder keeps compact, durable project context so new sessions do not need
to rediscover the same repository structure, decisions, and active work from
scratch.

## Read Order

Start here for non-trivial work:

1. `current-focus.md`
2. `project-map.md`
3. `domain-map.md`
4. `decisions.md`
5. `engineering-principles.md`
6. `backlog.md`
7. `changelog.md`

These files reduce discovery cost, but they do not replace the codebase. If a
context file conflicts with the repository, trust the repository and update the
context file.

## File Roles

- `current-focus.md`: current branch state, active themes, next likely work
- `project-map.md`: structural map of the repository and major subsystems
- `domain-map.md`: main business flows and where behavior lives
- `decisions.md`: stable technical decisions and operating rules
- `engineering-principles.md`: project-specific heuristics for abstraction, pattern fit, and layer ownership
- `backlog.md`: pending technical or product-adjacent work items
- `changelog.md`: append-only summary of meaningful recent changes

## Update Rules

Update these files after meaningful work:

- Always update `changelog.md`
- Update `current-focus.md` when active priorities, blockers, or likely next steps change
- Update `backlog.md` when status, priority, or new tasks change
- Update `decisions.md` only when a real technical decision is made or reversed
- Update `project-map.md` or `domain-map.md` only when structure, ownership, or main flows change

Keep entries concise and technical. Avoid narrative logs and noisy line-by-line
change descriptions.
