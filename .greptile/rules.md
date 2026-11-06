# Review rules

Review as `## Guidelines` in `docs/agents/review.md` prescribes, with the files listed in
`.greptile/files.json` that bear on the diff.

Post an inline comment only for a finding with all five parts: Severity (P0, P1, or P2),
Location, Evidence, Impact, Fix. A suspicion without a concrete failure path is one
`Question:` line in the summary comment.

Leave what PHPCS, Psalm, and the tests catch, and taste, to the tools and the author.
