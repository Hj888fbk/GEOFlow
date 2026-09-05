# GEOFlow v3.0.0 恢复与稳定化状态（2026-09-05）

本文件属于 `GF-PLATFORM` 的开发/恢复证据，不是运行成功回执，也不授权启动正式 worker、导入、生成、审核、监测或分发。

## 已完成

- 混合工作树的 52 个已跟踪修改和 8 个未跟踪文件已保全到 `recovery/geoflow-mixed-baseline-20260904@adb71a8e`，没有重置、删除或直接发布。
- 已建立五个独立工作树：登录提交锁、AI 可见性项目配置、AI 质检与生成、WordPress 分发、百业网等渠道适配器。
- 已固定稳定代码目标 `v3.0.0@f5301eb1`；升级指南固定为 `main@1a492812` 中的独立 blob，二者不混为同一版本。
- `docker-compose.stable-freeze.yml` 将 scheduler 和各类写入 worker 放入默认不启用的 `production-writers` profile。该配置尚未启动容器。
- 该 overlay 已通过独立 Compose 语法解析；与 `docker-compose.prebuilt.yml` 的完整合并仍需真实 `.env.prod` 和镜像名，当前没有用示例配置冒充生产配置。
- 已保全源码 bundle、工作树补丁、未跟踪文件归档、storage 归档、无值配置摘要、源码清单和敏感信息扫描结果；逐项 SHA-256 见 `recovery-baseline-20260905.json`。

## 当前阻塞

- Docker Desktop Linux 引擎仍未运行；当前只证明进程层面没有容器/worker 运行，不能证明数据库中的任务和渠道已经持久化为 paused。
- 受认证的任务、渠道、队列、迁移状态回读尚未完成；13 条失败质检任务没有重试或删除，其持久化状态当前未知。
- 恢复目录存在一个具有 `PGDMP` 头的数据库文件，但同批状态记录仍为 `blocked_pending_docker`，本机也没有可用的 `pg_restore`。在完成目录清单、隔离恢复和业务抽查前，该文件只记为“存在但未验证”，不能作为已完成数据库备份。
- storage 归档尚未执行隔离恢复；稳定运行目录也没有执行迁移、安全审计或草稿态端到端试验。

## 恢复顺序

1. 启动 Docker 后，先只启动 PostgreSQL、Redis、app、web 等读回组件，不启用 `production-writers` profile。
2. 使用受支持的后台、CLI 或 API 完成 authenticated readback；确认任务和渠道持久化暂停，并等待在途任务终态。
3. 重新执行并验证 `pg_dump`，随后在隔离数据库完成 `pg_restore --list`、实际恢复和关键业务表抽查。
4. 核对 migration status、队列状态和 13 条失败质检任务分类；未经分类不得重试。
5. 按 V3 指南逐项验证回填、图片 readiness、安全审计和测试草稿；全部通过前不恢复 scheduler、worker 或外部分发。

## 结论

源码保全和稳定目录门禁已经完成；运行态冻结回读、数据库可恢复性和业务验收仍为阻塞。`sent`、容器可启动或备份文件存在都不能代替官网独立回读和恢复演练。
