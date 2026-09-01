# hengjia-content-package/v1 契约

## 顶层字段

必须包含：

- `schema_version`: 固定 `hengjia-content-package/v1`
- `page`: `role、audience、intent、target_channels`
- `seo`: `title、h1、slug、primary_keyword、secondary_keywords、summary、meta_description`
- `claims`: 可追溯主张；资质主张带完整 `qualification`
- `body_sections`: `heading、body、applicability、source_ids`
- `parameters`: `name、value、unit、applicability、evidence_status、source_ids`
- `applications`: `scenario、suitability、conditions、source_ids`
- `faq`: `question、answer、applicability、source_ids`
- `sources`: `source_id、title、evidence_status、public_permission`
- `internal_links`: `anchor、target_role、target_url`
- `media`: `source_id、usage、alt、rights_status`
- `schema_nodes`: `type、payload_json、source_ids`
- `prohibited_claims`: 明确禁止出现的主张
- `blockers`: `code、message`
- `prompt_meta`: `recipe_version、input_hash、model、generated_at、pipeline_versions`

## 结构规则

- `slug` 只允许小写字母、数字和连字符。
- 所有公开主张、参数、正文段落、FAQ、媒体和 Schema 都应能追到 `sources.source_id`。
- Schema 只能复述页面可见且可公开事实；不得生成 Offer、AggregateRating、库存、固定价格、虚构作者或不存在的资质。
- `applications` 必须区分 `suitable`、`conditional`、`not_suitable`，并保留条件。
- 一个核心搜索意图只有一个主页面；重叠时输出更新建议。
- 内容包中存在 error 或 blocker 时，不得进入渠道映射或发布流程。

## 验证命令

```powershell
python scripts/validate_content_package.py path\to\package.json --as-of 2026-08-31
```

命令只读输入并把 JSON 报告写到标准输出。退出码：`0` 表示候选通过确定性门禁，`2` 表示结构错误或 blocker，`1` 表示文件或 JSON 读取失败。通过不等于人工审核、生产发布或业务结果。

## 输出 contract

验证报告固定返回：`schema_version、candidate_status、valid、errors、warnings、blockers、metrics、package_sha256`。内容包哈希使用 UTF-8、JSON key 排序和紧凑分隔符计算。
