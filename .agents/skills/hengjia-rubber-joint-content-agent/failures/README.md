# Failure taxonomy

本目录记录候选 Skill 的稳定失败类别，不记录账号、Cookie、Token、客户资料或原始私密正文。

- `competitor_fact_conversion`：把同行、第三方或 AI 信息转成恒佳事实。
- `qualification_inflation`：资质字段或反查不完整仍宣传。
- `unsupported_parameter`：精确参数缺少恒佳同型号证据。
- `cross_channel_drift`：渠道改写改变主体、事实、参数或限定条件。
- `unsafe_execution`：尝试登录、处理验证码、发布或写远端。
- `secret_exposure`：敏感凭据进入输入、输出或日志。
- `state_inflation`：把候选、发布、抓取、收录、排名、AI 提及或询盘状态混写。
- `duplicate_intent`：同一核心搜索意图生成多个主页面。

真实失败案例在脱敏、人工批准并完成影响评估前不得加入 Skill 包。当前仅有 recorded fixture，真实运行失败为 `missing evidence`。
