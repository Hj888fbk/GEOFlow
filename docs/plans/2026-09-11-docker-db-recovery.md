# Docker 数据库连接故障修复记录（2026-09-11 晚）

> 结论：本轮共修复 **4 类问题**（dev Redis 密码不匹配 / 双实例共写数据目录 / pg_control 计数器损坏 / prod 数据目录错配）。
> prod 栈已完全恢复：**111/111 张表可读，首页与后台均 200**。
> 数据损失范围：`articles`、`article_ai_quality_checks`、`manual_publication_batches` 三张表回滚到 **2026-09-09 备份**，详见 §4。

## 1. 故障根因链（完整取证）

```
.env:225  POSTGRES_DATA_DIR=./docker-data/dev/postgres   ← prod compose 被指到 dev 数据目录
     ↓
09-11 18:28  dev 与 prod 两套栈同时重启 → 两个 PostgreSQL 进程
             共写同一 PGDATA（Windows bind mount 下 postmaster.pid 锁不可靠）
     ↓
22:18  dev 栈 Redis 修复后，6 个队列容器恢复工作 → 高并发写触发
       "MultiXactId 1727 has not been created yet"（两个实例的多事务状态互不知晓）
     ↓
22:36  重启 prod postgres 做恢复 → 双实例交错写入的 WAL 无法定位检查点
       PANIC: could not locate a valid checkpoint record → 崩溃循环
```

另有一个独立问题：dev 栈 Redis 创建于 09-09 10:08（当时 `.env` 还没写密码），
`.env` 一小时后补了密码 → dev 队列容器发 AUTH 被无密码 Redis 拒绝 → 无限重启。

## 2. 修复动作清单

| # | 动作 | 结果 |
|---|---|---|
| 1 | `docker-compose.yml` redis 服务补 `REDIS_PASSWORD`/`--requirepass`/healthcheck（对齐 prod），重建 dev-redis | ✅ 6 个 dev 队列容器恢复正常 |
| 2 | `docker stop geoflow-postgres`（立即消除双写者） | ✅ 单写者原则恢复 |
| 3 | `pg_resetwal -f`（先补零扩展 pg_multixact SLRU 文件）+ **二进制修补 pg_control**：`nextMulti=2100, nextMultiOffset=5200, oldestMulti=1, oldestMultiDB=16384`，重算 CRC32C（crc 偏移 292），`pg_resetwal -n` 校验通过 | ✅ 服务器可启动 |
| 4 | 全库 111 表逐表 pg_dump：**108 张健康表抢救成功**，仅 3 张损坏 | ✅ 抢救最大化 |
| 5 | 重建全新数据目录 `docker-data/prod/postgres-rebuilt`，按序恢复：schema → 108 表数据 → 3 张损坏表取自 09-09 备份 | ✅ articles=11, ai_checks=49, mp_batches=2 |
| 6 | `.env:225` 改为 `POSTGRES_DATA_DIR=./docker-data/prod/postgres-rebuilt`；dev compose 改用独立变量 `DEV_POSTGRES_DATA_DIR` | ✅ 根因消除，dev/prod 数据目录彻底隔离 |
| 7 | 重启全部 prod 应用容器 | ✅ 111/111 表可读，web 200 |

## 3. 修改的文件

- `docker-compose.yml`（redis 服务对齐 prod + postgres 卷改 `DEV_POSTGRES_DATA_DIR`）
- `.env`（POSTGRES_DATA_DIR 指向新目录）
- `routes/web.php`、`routes/api.php`、`routes/channels.php`（上一轮路由修复，本轮已重建路由缓存生效）
- 新增备份：`backups/schema_20260911.dump`、`backups/data_20260911.dump`、`backups/raw-datadir-frozen-20260911/`（故障现场冻结拷贝）

## 4. 数据损失说明（重要）

| 表 | 状态 |
|---|---|
| **articles**（11 篇） | 回滚到 09-09 备份。备份后新增的文章（id 27~36 区间若存在）**已丢失** |
| **article_ai_quality_checks**（49 条） | 回滚到 09-09。可在后台对文章重新发起 AI 质检重建 |
| **manual_publication_batches**（2 条） | 回滚到 09-09 |
| 其余 108 张表 | **修复当日完整保留**（含当天全部数据） |
| worker_heartbeats | 心跳数据，有 1 条重复键告警，无影响 |

建议：登录后台检查文章列表是否齐全；缺失的文章若有原始素材可重新导入。

## 5. 遗留事项 / 仍需注意

1. **dev 旧栈已停用**（6 个队列容器已 stop，dev-postgres 已停）。它挂载的旧数据目录已损坏，若以后要用 dev 栈：删掉 `docker-data/dev/postgres` 重新初始化 + `php artisan migrate` 即可。
2. **备份节奏**：本次事故暴露"无自动备份"的裸奔状态。强烈建议加一条每日 cron：`docker exec geoflow-laravel-prod-postgres-1 pg_dump -U geo_user -d geo_flow -Fc -f /tmp/daily.dump`。
3. **不要再把两套栈指到同一数据目录**——本次事故的根本原因。现在 dev/prod 变量已隔离，但仍建议非必要不同时开两套栈。
4. Windows bind mount 上跑 PG 数据目录本身就有 fsync 可靠性风险，本次损坏能这么快恢复全靠 09-09 备份。长期看建议把数据目录迁到 Docker named volume。
5. 路由修复（R1/R2/R3）已写入文件并重建 dev 路由缓存；**prod 镜像里的代码是打包时固化的**，下次重建镜像时才会带上这些修复。

## 6. 验证记录

- `php artisan tinker`：111 张表逐一 count → readable=111 failed=0
- `curl http://127.0.0.1:18080/` → 200；`/geo_admin/login` → 200
- 全部 prod 容器 Up (healthy)，队列容器正常 bootstrap
- 故障修复后日志尾部 0 条新增 MultiXact 错误
