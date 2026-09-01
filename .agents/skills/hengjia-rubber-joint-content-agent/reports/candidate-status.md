# Governed candidate status

- Skill：`hengjia-rubber-joint-content-agent`
- Version：`0.1.0-candidate.2`
- Owner：恒佳 GEOFlow 内容治理组
- Updated：2026-08-31
- Review due：2026-09-07
- Output contract：`hengjia-content-package/v1` + `hengjia-channel-candidates/v1`
- Publication state：`candidate_only / not_published`
- Production ready：`false`

## Evidence classification

- 结构、脚本和本地断言：candidate.2 自动门禁已于 2026-08-31 刷新；候选 ZIP 与 package verify 通过。
- Runtime permission probes：OpenAI 与 generic 目标均通过；当前仅声明 metadata fallback，不声称客户端原生权限强制。
- 安装模拟与 registry audit：`blocked`；未跟踪的生成报告不会进入受治理 ZIP，安装包因此缺少 `reports/skill-overview.html` 和 `reports/review-studio.html`。
- output eval：`recorded fixture`，不是模型运行。
- 真人盲评：`missing evidence`。
- provider-backed 真实模型评测：`missing evidence`。
- 一周影子运行：`missing evidence`。
- WordPress 生产预览、备份、发布、回读和回滚：`missing evidence`。
- 爱采购、1688、搜狐号、百家号人工验收：`missing evidence`。

## rollback boundary

停用或移除本 Skill 目录即可回滚候选能力。不得把回滚扩大为数据库、官网、账号或远端平台动作，因为本候选未获准触碰这些系统。
