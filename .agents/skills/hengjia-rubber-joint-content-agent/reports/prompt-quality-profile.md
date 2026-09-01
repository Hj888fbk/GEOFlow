# Prompt Quality Profile

Skill: `hengjia-rubber-joint-content-agent`
Relevance: `prompt-heavy`
Overall quality score: `91.0/100`

## Primary Task Family

**Prompt engineering**
- Matched keywords: prompt, 提示词, 指令

## Complexity

- Band: `expert`
- Score: `26`
- Reason: multiple task families plus governance, evaluation, or expert-level constraints

## Need Model

- Explicit Need: 基于 GEOFlow 提供的获准资料和证据，重复生成或复核 hengjia-content-package/v1，并映射为 WordPress、百度爱采购、1688、搜狐号和百家号候选字段；只返回候选、证据与阻断，不执行发布。
- Implicit Need: The reusable skill needs a stable role, task, and output contract rather than a one-off prompt.
- Scenario: GEOFlow 内容任务：产品、受众、采购意图、页面职责、关键词和目标渠道, 获准来源索引与文件：source_id、SHA-256、证据状态、公开权限、审批状态和适用范围, 已复核证据主张：公司、资质、产品、生产、检测、案例和图片权利, 现有页面快照、页面地图、内链候选和五渠道字段合同
- User Level: infer from examples and standards; ask only if it changes output depth
- Success Standard: 触发评测覆盖正例、反例和 near-neighbor, 输出评测至少五例并覆盖 file-backed fixture、near-neighbor 和 boundary, 确定性脚本通过单元测试、Python 兼容、信任检查和帮助面 smoke test, recorded fixture、真实模型评测和真人盲评状态必须分开记录, 未完成一周影子运行、生产回读和人工签字前不得标为 production-ready

## RTF To Skill Mapping

- Role: Use a prompt engineer role only when role design materially improves execution.
- Task: Map Role, Task, and Format into skill behavior rather than copying a large prompt template.
- Format: Return a compact prompt contract plus tests, quality matrix, and usage notes.

## Quality Matrix

### Completeness — 100/100
- Matched signals: output, 输入, 输出, 标准
- Repair: Name missing inputs, outputs, constraints, or success standards before deepening the package.

### Clarity — 85/100
- Matched signals: 明确
- Repair: Replace broad verbs with observable actions and define what done means.

### Consistency — 95/100
- Matched signals: boundary, 一致, 边界
- Repair: Check that role, task, format, exclusions, and examples do not contradict each other.

### Practicality — 95/100
- Matched signals: use, 执行, 使用
- Repair: Add runnable steps, examples, or verification cues instead of abstract advice.

### Specificity — 80/100
- Matched signals: none
- Repair: Anchor wording in the user's audience, domain nouns, and target outcome.

## Matched Task Families

### Prompt engineering
- Score: `3`
- Keywords: prompt, 提示词, 指令
- Role: Use a prompt engineer role only when role design materially improves execution.
- Task: Map Role, Task, and Format into skill behavior rather than copying a large prompt template.
- Format: Return a compact prompt contract plus tests, quality matrix, and usage notes.

### Creative generation
- Score: `2`
- Keywords: content, 内容
- Role: Use a taste-aware creator role with clear audience, tone, and originality boundaries.
- Task: Generate variants, explain selection logic, and preserve the user's distinctive constraints.
- Format: Return options with rationale, selection criteria, and refinement paths.

### Execution operation
- Score: `1`
- Keywords: 执行
- Role: Use an operator role with explicit boundaries, inputs, outputs, and failure handling.
- Task: Convert the job into ordered steps with validation checks and stop conditions.
- Format: Return a runbook-like handoff with commands, checks, owners, and next actions when relevant.

## Self-Repair Checks

- Check explicit need, implicit need, scenario, user level, and success standard before deepening.
- Map Role, Task, and Format into skill behavior, not decorative prompt labels.
- Ask one focused clarification only when missing information changes the package boundary.
- Add tests or examples for prompt-heavy behavior before treating it as reusable.
- Keep prompt methodology in references and reports instead of bloating SKILL.md.

## Reviewer Note

Use this profile when the package depends on prompt behavior, role design, output contracts, or conversation quality.
