# Package Verification

- OK: `True`
- Package directory: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\packages`
- Targets: `1 / 1` adapters present
- Archive present: `True`
- Archive SHA256: `03bbead3034d67316eab63fd481a34c6a714a65ff0fa72d5ea72efcaac768592`
- Nested SKILL.md entries: `0`
- Failures: `0`
- Warnings: `1`

## Checks

| Check | Status | Detail |
| --- | --- | --- |
| `package-manifest` | `pass` | Package manifest exists: D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\packages\manifest.json |
| `openai-adapter` | `pass` | Adapter exists for target: openai |
| `archive-safe-paths` | `pass` | Archive has no absolute or parent-traversal entries |
| `archive-entry-hengjia-rubber-joint-content-agent/SKILL.md` | `pass` | Archive contains hengjia-rubber-joint-content-agent/SKILL.md |
| `archive-entry-hengjia-rubber-joint-content-agent/manifest.json` | `pass` | Archive contains hengjia-rubber-joint-content-agent/manifest.json |
| `archive-entry-hengjia-rubber-joint-content-agent/agents/interface.yaml` | `pass` | Archive contains hengjia-rubber-joint-content-agent/agents/interface.yaml |
| `archive-single-skill-entrypoint` | `pass` | Archive exposes only the root SKILL.md entrypoint |
| `archive-excludes-generated` | `pass` | Archive excludes local caches, platform noise, .yao state, external submission drafts, local evidence pointers, generated dist/, .previews/, and tests/tmp* contents |
| `archive-portable-evidence-index` | `pass` | Archive includes a self-contained portable evidence pointer and verified report index |

## Failures

- None

## Warnings

- Registry audit was not supplied; package verification skipped metadata parity checks.
