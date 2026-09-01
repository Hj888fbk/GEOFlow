# Security Trust Report

- OK: `True`
- Scanned files: `25`
- Scripts: `3`
- Internal script modules: `1`
- Secret findings: `0`
- Network-capable scripts: `0`
- Network policy covered scripts: `0`
- Network policy missing scripts: `0`
- File-write scripts: `0`
- Permission approvals: `0 / 0`
- Permission approval gaps: `0`
- CLI help smoke checked: `2`
- CLI help smoke failures: `0`
- Interactive scripts: `0`
- Package hash scope: `source-contract-without-generated-reports`
- Package hash files: `25`
- Package SHA256: `0b291dd6a075a12d75f86e9f0ed6c2e0d85f20ac22aaede27f802bbbfe75dd84`

## Failures

- None

## Warnings

- No dependency or lock file detected

## Dependency Evidence

- Files: `none`
- Pinned entries: `0`
- Unpinned entries: `0`

## Network Policy

- Policy file: `security/network_policy.json`
- Present: `False`
- Covered scripts: `0`
- Missing scripts: `none`
- Mismatches: `0`

## Permission Governance

- Policy file: `security/permission_policy.json`
- Present: `True`
- Required capabilities: `none`
- Approved capabilities: `none`
- Missing approvals: `none`
- Invalid approvals: `none`
- Expired approvals: `none`

## CLI Help Smoke

- Enabled: `True`
- Timeout seconds: `5.0`
- Checked scripts: `2`
- Passed scripts: `2`
- Failed scripts: `none`

## Script Surface

| Script | Interface | Declared | Argparse | Main Guard | Input | Network | File Write | Subprocess | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| scripts/contract.py | internal-module | True | False | False | False | False | False | False | Imported by validate_content_package.py and map_channel_candidates.py. |
| scripts/map_channel_candidates.py | cli | False | True | True | False | False | False | False | Default CLI classification; add SCRIPT_INTERFACE for internal modules. |
| scripts/validate_content_package.py | cli | False | True | True | False | False | False | False | Default CLI classification; add SCRIPT_INTERFACE for internal modules. |
