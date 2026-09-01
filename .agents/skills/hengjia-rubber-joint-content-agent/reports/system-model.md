# System Model

Skill: `hengjia-rubber-joint-content-agent`

- Stability score: `92/100`
- Stability band: `system-ready`
- Doctrine: Structure drives behavior: improve the boundary, feedback loops, drift watch, and leverage points before adding weight.

## System Boundary Map

- Owned job: 基于 GEOFlow 提供的获准资料和证据，重复生成或复核 hengjia-content-package/v1，并映射为 WordPress、百度爱采购、1688、搜狐号和百家号候选字段；只返回候选、证据与阻断，不执行发布。
- Output boundary: 一个 hengjia-content-package/v1 候选 JSON、确定性验证报告、hengjia-channel-candidates/v1 五渠道候选 JSON，以及来源 ID、blockers、missing evidence 和 not_published 状态。
- Maturity assumption: `governed`
- Input boundary:
  - GEOFlow 内容任务：产品、受众、采购意图、页面职责、关键词和目标渠道
  - 获准来源索引与文件：source_id、SHA-256、证据状态、公开权限、审批状态和适用范围
  - 已复核证据主张：公司、资质、产品、生产、检测、案例和图片权利
  - 现有页面快照、页面地图、内链候选和五渠道字段合同
- Non-goals:
  - 登录账号、处理或绕过验证码、保存 Cookie/Token/API Key、点击最终发布或调用远端发布接口
  - 替代或修改 GEOFlow 数据库、生产 Prompt、WordPress 生产内容或现有自动任务
  - 把同行官网、第三方页面、AI 回答或行业资料转成恒佳企业事实或产品参数
  - 纯竞品调研、通用写作、一次性翻译总结和无证据宣传稿
- Constraints:
  - 只有 public_record_verified 或 internal_confirmed_public 且 publishable 的获准主张可写成恒佳事实
  - 资质与精确参数缺少任一必要证据时必须阻断
  - 同一核心搜索意图只有一个主页面，优先更新现有页面
  - 五渠道可调整表达，但主体、事实、参数、联系方式、来源和限定条件必须一致
  - Skill 只读明确输入并输出到标准输出或调用方指定候选文件，不使用网络、浏览器或账号能力
- Standards:
  - 触发评测覆盖正例、反例和 near-neighbor
  - 输出评测至少五例并覆盖 file-backed fixture、near-neighbor 和 boundary
  - 确定性脚本通过单元测试、Python 兼容、信任检查和帮助面 smoke test
  - recorded fixture、真实模型评测和真人盲评状态必须分开记录
  - 未完成一周影子运行、生产回读和人工签字前不得标为 production-ready
- Human judgment boundary:
  - Infer non-core gaps visibly; ask one focused clarification only for an unresolved core job, primary output, or explicit direction conflict.
  - Escalate visible tradeoffs when benchmark patterns conflict with local privacy, naming, or governance constraints.
  - Do not silently broaden the skill into adjacent jobs just because the examples are nearby.

## Feedback Loops

### Intent boundary loop

- Signal: Intent confidence score is 100/100.
- Response: Ask only the highest-leverage clarification before adding package weight.
- Evidence: reports/intent-confidence.md and reports/intent-dialogue.md

### Reference synthesis loop

- Signal: Benchmark patterns are useful only after they are abstracted into borrow and avoid guidance.
- Response: Borrow one pattern at a time and keep the rest as reviewer-visible evidence.
- Evidence: reports/reference-synthesis.md

### Output quality loop

- Signal: Generated output may fail in recurring domain-specific ways.
- Response: Apply predicted output-risk families as self-repair checks before final output.
- Evidence: reports/output-risk-profile.md
- Current risk families:
  - Code and command safety
  - Markdown readability
  - Citation and footnote clutter
  - Screenshot and visual capture
  - Tone and specificity

### Reviewer feedback loop

- Signal: Human review catches drift that static checks miss.
- Response: Capture lightweight feedback and turn repeated findings into gates or references.
- Evidence: reports/review-viewer.html and feedback records

### Lifecycle loop

- Signal: As reuse grows, the skill needs stronger gates, ownership, and regression evidence.
- Response: Promote only when the next gate improves reliability more than context cost.
- Evidence: manifest.json, reports/iteration-directions.md, and governance checks

## Delay And Drift Watch

### Trigger drift

- Watch signal: Users start invoking the skill for adjacent one-off or explanation-only requests.
- Countermeasure: Add near-neighbor exclusions and route evals before expanding workflow steps.
- Cadence: per trigger or description change

### Output drift

- Watch signal: Outputs remain valid but become generic, cluttered, or weakly aligned with the user's domain.
- Countermeasure: Refresh output-risk and artifact-design profiles, then add one self-repair check.
- Cadence: after the first 3-5 real uses
- Risk families:
  - Code and command safety
  - Markdown readability
  - Citation and footnote clutter
  - Screenshot and visual capture
  - Tone and specificity

### Reference drift

- Watch signal: Borrowed benchmark patterns no longer fit the local job or add ceremony without payoff.
- Countermeasure: Re-run reference synthesis and keep only patterns that improve the current boundary.
- Cadence: per material benchmark or product assumption change

### Governance drift

- Watch signal: Skill usage becomes team-critical while ownership, review cadence, or rollback evidence stays informal.
- Countermeasure: Promote maturity tier and add reviewer-visible lifecycle evidence.
- Cadence: monthly

## Failure Pattern Map

### Boundary failure

- Symptom: The skill handles nearby requests that were never part of the recurring job.
- Repair: Narrow the description and add explicit non-goals before adding more execution steps.

### Feedback gap

- Symptom: The skill has rules but no signal telling authors which rule should change after use.
- Repair: Turn repeated reviewer feedback into one eval, one reference note, or one self-repair check.

### Output degradation

- Symptom: The result is structurally correct but generic, cluttered, or weakly matched to the user's domain.
- Repair: Use output-risk families as pre-final checks.
- Current Risk Families:
  - Code and command safety
  - Markdown readability
  - Citation and footnote clutter
  - Screenshot and visual capture
  - Tone and specificity

### Prompt-behavior mismatch

- Symptom: The role, task, and format are copied from a prompt instead of becoming stable skill behavior.
- Repair: Convert reusable role/task/format assumptions into workflow, reports, or references.

## Highest Leverage Moves

### 2. Tune the frontmatter description

- Why: The description is the highest-leverage routing surface.
- Move: Name the recurring job, expected input, output, and strongest non-goal in compact language.

### 3. Install output self-repair checks

- Why: The likely failure families are: Code and command safety, Markdown readability, Citation and footnote clutter.
- Move: Add only the checks that prevent recurring output mistakes.

### 5. Close the lifecycle loop

- Why: Team-reused skills need visible ownership, review cadence, and regression evidence.
- Move: Keep manifest, review viewer, and iteration directions aligned after each material change.

## Reviewer Use

- Reviewer should ask whether the skill's structure will keep producing the desired behavior after repeated real use.
- Prefer changing the system boundary, feedback loop, or leverage point before adding more prose.
- If a problem repeats, convert it into a named failure pattern and one regression check.
