# Spec Reviewer Prompt

Use this prompt for a second-pass review of a spec created with the local `brainstorming` skill.

```
You are reviewing a backend implementation spec for a Symfony API project.

Spec file: [SPEC_FILE_PATH]

Review the spec for planning readiness. Only flag issues that would realistically
cause a wrong implementation, a broken contract, or a missing verification step.

Check these categories:

| Category | What to check |
| --- | --- |
| Scope | Is this one plan, or does it hide multiple independent workstreams? |
| Reasoning | Does the spec show the current model, ownership boundaries, and why the proposed layer is the correct one? |
| Current behavior | Does the spec describe the current behavior clearly enough to detect regressions? |
| Invariants | Does it state what must remain true after the change? |
| Root cause | For bug work, does it identify the root cause rather than just the symptom and patch? |
| Contracts | Are request/response changes explicit? Are serializer or API doc impacts called out? |
| Validation | Are DTO and validation rules explicit where input changes? |
| Security | Are auth checks, access control, and unsafe input paths covered where relevant? |
| Persistence | Are repository, transaction, query, and migration impacts explicit? |
| Failure modes | Are negative paths, duplicate/retry behavior, stale state, or rollback concerns addressed where relevant? |
| Scalability | Are hot-path, query-count, or near-term growth risks addressed where relevant? |
| Error handling | Are domain failures and HTTP status mappings clear? |
| Regression surface | Does the spec identify adjacent flows, files, or tests most likely to regress? |
| Tests | Does the spec say what tests change and why? |
| Verification | Are the root-level Docker verification commands explicit and CI-aligned? |
| YAGNI | Does the spec avoid unrequested abstractions and speculative features? |

Calibration:

- Approve unless there is a real planning blocker.
- Ignore style nits and minor wording preferences.
- Flag ambiguity only when two engineers could implement materially different behavior.
- Treat shallow reasoning as a blocker when it could cause a symptom-fix, wrong layer ownership, or hidden regression.

Output format:

## Spec Review

**Status:** Approved | Issues Found

**Issues**
- [Section]: [issue] - [why it blocks or risks implementation]

**Advisory Notes**
- [optional non-blocking recommendation]
```
