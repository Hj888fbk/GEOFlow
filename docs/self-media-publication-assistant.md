# GEOFlow 按需自媒体发布助手

## 当前边界

自媒体发布助手与官网生产解耦。文章在 GEOFlow 中显示为 `published` 不构成分发资格；只有官网责任侧 `HJ-WEB` 提交正式 URL、HTTP 200、母稿哈希和在线回读哈希一致的回执后，文章才进入可分发列表。

默认没有任务开启自动策略。未手动加入计划且未明确开启任务级自动策略时，系统只保存官网回执，不创建批次、不创建工作单、不调用模型，也不触发浏览器。

当前开发阶段实现八个平台的确定性路由和独立内容改写；Chrome 自动填充适配器只启用百家号、搜狐号。知乎专栏、CSDN、企鹅号、网易号、大鱼号和普通图文微博保留“打开编辑页并复制结构化内容”的安全降级路径，待前两个平台完成真实账号 UAT 后再逐个平台启用。

## 数据流

1. `HJ-WEB` 向官网回执 API 提交在线回读结果。
2. GEOFlow 验证文章当前标题、摘要、正文的 SHA-256 是否同时匹配 `source_hash` 和 `readback_hash`。
3. 未开启自动策略时，仅把文章列为可分发候选。
4. 超级管理员手动选择平台，或已启用的任务按固定规则创建批次。
5. 生成操作进入专用 `self-media` 队列；Horizon 最多运行两个该队列进程。
6. 批次在创建时冻结任务的执行管理员、授权版本和请求模型安全快照；每次生成领取独立租约。模型调用经过统一锁、调用前授权、调用后配置复核和用量审计，平台工作单在同一个受控回调内持久化。
7. 每个平台独立生成。成功稿不会因另一个平台失败而重复生成；失败批次只重试缺失平台。
8. GEOFlow 审核通过后，平台工作单进入 Chrome 队列。浏览器同一时间只能持有一条工作单。
9. 扩展核验域名、登录账号、验证码、编辑器为空和页面字段限制后填充草稿，绝不点击发布。
10. 用户在平台侧审核并点击发布，扩展检测公开 URL；只有对该 URL 的匿名 HTTP 200 回读成功，且回读最终 URL 与完成回执 URL 一致，才可回传 `completed`。

## 安装与队列

```bash
php artisan migrate
php artisan horizon
```

`config/horizon.php` 中的 `supervisor-self-media` 固定监听 `self-media` 队列，`maxProcesses=2`。`composer dev` 以及三套 Docker Compose 队列进程也包含该队列。生产环境需要共享缓存和正常运行的队列守护进程。

调度器每分钟执行 `geoflow:recover-self-media-batches`。如果 Worker 异常退出导致生成租约过期，批次会安全转为 `failed`、清除租约并保留错误记录，之后只能由人工重新入队，避免静默重复产生模型费用。

## 官网回执 API

接口：

```text
POST /api/v1/articles/{article}/website-publication-receipt
Authorization: Bearer <具有 articles:publish 的 GEOFlow Token>
X-Idempotency-Key: <每次逻辑提交的稳定唯一键>
```

示例请求：

```json
{
  "receipt_id": "HJ-WEB-20260907-001",
  "responsible_project_id": "HJ-WEB",
  "formal_url": "https://www.example.com/articles/example",
  "http_status": 200,
  "source_hash": "64位小写SHA-256",
  "readback_hash": "与source_hash相同的64位小写SHA-256",
  "verified_at": "2026-09-07T10:30:00+08:00"
}
```

哈希输入是规范化后的 `title`、`excerpt`、`content` JSON：移除 HTML 标签、解码实体、连续空白合并为一个空格，再使用未转义 Unicode 的 JSON 做 SHA-256。官网责任侧应复用同一契约，不能用本地 `published` 字段代替在线回读。

API 具备权限、限流和幂等保护。相同幂等键与相同请求会返回原回执；哈希不一致返回 `website_readback_failed`，不会产生自媒体对象。

## 管理端使用

入口：`内容管理 → 人工发布 → 按需自媒体发布`。

- 可分发文章：只读取已验证官网回执，不调用模型。
- 自媒体候选：提示可选择的平台，不创建工作单。
- 发布批次：手动勾选几个平台就只生成几个平台稿。
- 任务级策略：默认关闭；可保存内容意图和平台覆盖。自动来源每天最多一篇，同时最多两个待处理批次。
- 生成失败：显示逐平台错误；点击“重试缺失平台”不会重写已成功版本。
- 审核通过：要求每个平台已有同一身份下的活动账号、编辑入口和已启用适配器；整批在一个事务内处理，任何平台配置缺失都不会造成半批进入队列。
- 取消：停止后续处理，但保留批次、工作单和状态历史。

八个平台标识：`qq_penguin`、`zhihu_column`、`baijiahao`、`netease_media`、`sohu_media`、`weibo`、`csdn`、`dayu`。

## Chrome 账号与操作

在人工发布设置中配置平台账号：账号名称、主页 URL、编辑入口、账号 UID 或主页标识，并显式启用浏览器适配器。Cookie、密码和平台 Token 不得录入 GEOFlow。

扩展使用账号主页 URL、UID 或主页标识核验当前登录账号。遇到以下情况会在任何写入前停止，并保留“打开目标页 + 复制正文”的降级方式：

- 域名或账号不匹配；
- 未登录或出现验证码；
- 标题、摘要、正文或标签已有内容；
- 必需编辑器节点消失；
- 页面通过 `maxlength` 暴露的字段限制被超出。

扩展显示批次冻结的封面和正文图片清单，图片仍由用户手动上传。草稿填充成功后，工作单继续由当前 Chrome Token 持有；为避免另一浏览器误碰平台侧非空草稿，此时不能释放工作单。Chrome 重启后可恢复账号已核验状态和心跳。

“确认已发布”在 v2 工作单中默认禁用，只有点击“检测发布结果”并完成匿名 HTTP 200 回读后才会启用；扩展把回读最终 URL 与完成 URL 绑定，服务端也会拒绝缺少成功回读或两个 URL 不一致的完成回执。

## 状态与故障

工作单主链路：

```text
draft → ready → in_progress → draft_filled → completed/outcome_unknown/failed/cancelled
```

批次根据子工作单汇总为 `planned`、`generating`、`pending_review`、`pending_platform`、`active`、`pending_verification`、`completed`、`failed`、`cancelled` 或 `invalidated`。

母稿标题、摘要或正文变化时，旧批次变为 `invalidated`，子工作单写入 `source_stale_at`，不会静默覆盖已填平台草稿，也不会再出现在浏览器队列。

任务执行管理员被停用、AI 配置授权版本变化、请求模型失效，或模型在响应后发生配置变化时，结果都会在工作单写入前被拒绝。供应商已经返回但持久化失败的调用记为 `discarded`，不会被误记成成功。

## 验收层级

自动化测试只证明本地契约和模拟 DOM 行为。真实百家号、搜狐号验收必须使用专用 Chrome Profile 和明确账号，依次完成：

1. 只填不发，核对标题、摘要、正文、标签、图片清单和账号阻断。
2. 用户人工发布一篇，取得公开 URL。
3. 扩展匿名回读公开 URL，回传与该 URL 绑定的回读凭证，GEOFlow 校验后显示完成。

在两平台真实 UAT 通过前，不得把本地测试称为平台发布完成，也不得启用后续平台适配器。
