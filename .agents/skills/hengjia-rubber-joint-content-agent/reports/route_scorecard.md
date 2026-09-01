# Route Scorecard

- evidence type: `recorded deterministic fixture`
- model executed: `false`
- human reviewed: `false`
- threshold: `0.48`
- total cases: `18`
- correct cases: `18`
- accuracy: `1.0`
- false positives: `0`
- false negatives: `0`
- ambiguous cases: `0`

| Bucket | Passed | Total | Pass rate |
| --- | ---: | ---: | ---: |
| should trigger | 6 | 6 | 1.0 |
| should not trigger | 6 | 6 | 1.0 |
| near-neighbor | 6 | 6 | 1.0 |

命令使用 `evals/improved_description.txt`、`evals/baseline_description.txt`、`evals/trigger_cases.json` 和 `evals/semantic_config.json`。结果只证明本地确定性夹具当前可复现，不能描述为真实模型路由、生产触发率或线上 telemetry。真实运行时激活、漏触发和误触发数据仍为 `missing evidence`。
