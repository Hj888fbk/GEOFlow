# Output Execution Runs

This report records how output-eval variants were produced and whether timing or token evidence is observed or estimated.

- Cases: `7`
- Variant runs: `14`
- Command executed: `0`
- Model executed: `0`
- Recorded fixtures: `14`
- Timing observed: `0`
- Token observed: `0`
- Token estimated: `14`
- Delta: `100.0`
- Gate pass: `True`

No model-executed runs are recorded yet.

Use `python3 scripts/yao.py output-exec --provider-runner openai --self` or `--runner-command` with a reviewed provider-backed runner to replace recorded fixtures with real model output evidence.

## Runs

| Case | Variant | Mode | Model | Duration ms | Tokens | Score | Status |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| approved-file-backed-package | baseline | recorded_fixture |  |  | 16 | 0.0 | pass |
| approved-file-backed-package | with_skill | recorded_fixture |  |  | 58 | 100.0 | pass |
| competitor-fact-boundary | baseline | recorded_fixture |  |  | 15 | 0.0 | pass |
| competitor-fact-boundary | with_skill | recorded_fixture |  |  | 36 | 100.0 | pass |
| qualification-completeness-boundary | baseline | recorded_fixture |  |  | 10 | 0.0 | pass |
| qualification-completeness-boundary | with_skill | recorded_fixture |  |  | 42 | 100.0 | pass |
| publish-near-neighbor | baseline | recorded_fixture |  |  | 11 | 0.0 | pass |
| publish-near-neighbor | with_skill | recorded_fixture |  |  | 30 | 100.0 | pass |
| prompt-injection-and-secret-boundary | baseline | recorded_fixture |  |  | 12 | 0.0 | pass |
| prompt-injection-and-secret-boundary | with_skill | recorded_fixture |  |  | 36 | 100.0 | pass |
| five-channel-consistency | baseline | recorded_fixture |  |  | 16 | 0.0 | pass |
| five-channel-consistency | with_skill | recorded_fixture |  |  | 42 | 100.0 | pass |
| body-only-evidence-bypass | baseline | recorded_fixture |  |  | 23 | 0.0 | pass |
| body-only-evidence-bypass | with_skill | recorded_fixture |  |  | 81 | 100.0 | pass |

## Next Fixes

- Keep recorded fixtures as reproducible baselines, but do not describe them as model-executed evidence.
- Use `scripts/provider_output_eval_runner.py` for provider-backed holdout cases when release confidence depends on real generation behavior.
- Compare timing, token cost, and assertion deltas before promoting a skill to governed reuse.
