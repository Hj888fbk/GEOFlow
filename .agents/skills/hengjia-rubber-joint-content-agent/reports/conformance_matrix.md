# Runtime Conformance Matrix

- Skill: `hengjia-rubber-joint-content-agent`
- Targets: `3`
- Passed: `3`
- Failed: `0`

| Target | Status | Failures | Warnings |
| --- | --- | --- | --- |
| openai | pass | None | None |
| generic | pass | None | None |
| agent-skills | pass | None | agent-skills uses canonical Agent Skills metadata; provider-native execution transforms are not implemented in v0. |

## Reviewer Notes

- Failed targets block release for that target.
- Warnings identify lossy or not-yet-compiled behavior that must remain visible.
