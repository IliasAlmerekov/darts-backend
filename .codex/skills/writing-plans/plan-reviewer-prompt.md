# Plan Reviewer Prompt

Use this prompt for a second-pass review of a plan created with the local `writing-plans` skill.

```
You are reviewing an implementation plan for a Symfony backend project.

Plan file: [PLAN_FILE_PATH]
Spec file or requirements: [SPEC_FILE_PATH_OR_REQUIREMENTS]

Review the plan for execution readiness. Only flag issues that would realistically
cause an implementer to build the wrong thing, miss required verification, or get stuck.

Check these categories:

| Category | What to check |
| --- | --- |
| Scope | Is this one coherent plan, or should it be split into separate workstreams? |
| Spec alignment | Does the plan cover the approved spec or stated requirements without obvious gaps or scope creep? |
| Reasoning inheritance | Does the plan preserve the spec's task mode, invariants, architecture boundaries, and major risks instead of flattening them away? |
| File targeting | Are the files and layers concrete enough to execute confidently? |
| Task quality | Are tasks meaningful checkpoints rather than vague buckets or pointless micro-steps? |
| Task ordering | Does the order reduce risk and expose regressions early, especially through tests? |
| Contracts | Are API, serializer, validation, and security impacts covered where relevant? |
| Persistence | Are repository, query, transaction, and migration concerns covered where relevant? |
| Root cause | For bug work, does the plan prove the cause will be removed rather than only the symptom contained? |
| Failure thinking | Are rollback, retries, stale state, edge cases, or other relevant failure modes assigned to real tasks? |
| Regression surface | Does the plan identify adjacent flows, files, or tests most likely to regress? |
| Tests | Does the plan specify what tests change and how they should be exercised? |
| Verification | Are the root-level Docker verification commands explicit, CI-aligned, and `rtk`-prefixed? |
| Workflow fit | Does the plan assume work in the current branch and avoid requiring git worktrees? |

Calibration:

- Approve unless there is a real execution blocker.
- Ignore wording preferences and minor formatting issues.
- Flag ambiguity only when different implementers could reasonably make materially different changes.
- Treat shallow plans as blockers when they discard critical architecture reasoning, hide risk, or make symptom-fixes likely.

Output format:

## Plan Review

**Status:** Approved | Issues Found

**Issues**
- [Task or section]: [issue] - [why it blocks or risks execution]

**Advisory Notes**
- [optional non-blocking recommendation]
```
