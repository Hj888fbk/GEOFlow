# 治理、评审与回滚

## 身份

- owner：恒佳 GEOFlow 内容治理组
- maturity：`Governed candidate`
- manifest status：`experimental`
- review cadence：每次发布候选，并至少每月一次
- 首次 review due：2026-09-07
- production ready：否

## output contract

Skill 只交付：

1. `hengjia-content-package/v1` 候选 JSON；
2. 确定性验证报告；
3. `hengjia-channel-candidates/v1` 五渠道候选 JSON；
4. 来源 ID、blockers、missing evidence 和“未发布”状态。

不交付账号会话、数据库记录、远端草稿、发布回执或收录/排名结论。

## 人工门禁

发布候选前需要内容治理负责人检查：主体与品牌口径、强事实来源、资质完整性、同型号参数、图片权利、重复意图、渠道字段差异和广告法风险。真人盲评必须在不知道 A/B 来源的情况下记录 reviewer、时间、选择和基于 rubric 的理由。

截至 2026-08-31，以下均为 `missing evidence`：

- provider-backed 真实模型输出评测；
- 真人盲 A/B 决策；
- 一周影子运行；
- WordPress 生产预览、备份、发布、字段回读和失败回滚；
- 爱采购、1688、搜狐号、百家号运营人员验收；
- 真实发布后的抓取、收录、排名、AI 提及与询盘数据。

recorded fixture 只能证明案例可复现和断言路径可运行，不能描述为模型效果或真人同意。

## rollback boundary

回滚仅限停用或移除 `hengjia-rubber-joint-content-agent` 候选目录并恢复调用方原有路由。本 Skill 不创建迁移、不写数据库、不修改 GEOFlow 生产 Prompt、不写官网、不保存账号状态，因此不应有生产数据回滚动作。若未来接入 GEOFlow，必须由主系统另行提供版本、审批、影子运行和可回滚发布。

## 晋升条件

自动门禁通过只是进入评审的必要条件。至少完成真实模型输出评测、真人盲评、一周影子运行、生产预览/回读验证和 owner 签字后，才可提出 production 候选；本 Skill 不得自行修改 `status` 或替换生产版本。
