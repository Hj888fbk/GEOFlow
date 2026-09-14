# Docker 数据库连接排查 + 路由模块审计报告

> 日期：2026-09-11 ｜ 范围：本机 Docker 全部容器 + `routes/*` 全部路由定义
> 结论先行：**数据库连接本身没有问题**（dev/prod 两套栈的 PostgreSQL 均连通）。真正导致容器反复崩溃的是**开发栈 Redis 密码不匹配**。路由模块整体健壮，未发现 P0/P1，发现 3 个 P2 + 4 个 P3。

---

## 第一部分：Docker 容器无法连接"数据库"排查

### 1.1 实际拓扑（排查确认）

| 容器 | 网络 | 状态 | 端口映射 |
|---|---|---|---|
| geoflow-laravel-prod-*（9 个服务） | `geoflow-prod-net` | ✅ 全部健康，队列正常处理任务 | 仅 web 映射 18080 |
| geoflow-laravel-prod-postgres-1 (pg18) | `geoflow-prod-net` | ✅ healthy | **无宿主机映射** |
| geoflow-laravel-prod-redis-1 (redis8) | `geoflow-prod-net` | ✅ healthy，**有密码** | **无宿主机映射** |
| geoflow-app / geoflow-scheduler / geoflow-reverb（旧开发栈） | `geoflow-laravel_default` | ✅ 运行中 | 无 |
| geoflow-laravel-ai-quality-queue-1/2、geoflow-queue、geoflow-knowledge-queue、geoflow-ai-quality-backfill-queue、geoflow-ai-optimization-queue（旧开发栈） | `geoflow-laravel_default` | 🔴 **无限重启循环（每 30~60 秒）** | 无 |
| geoflow-postgres (pg18) | `geoflow-laravel_default` | ✅ healthy | 127.0.0.1:15432 |
| geoflow-redis (redis8) | `geoflow-laravel_default` | ⚠️ 运行中但**无密码** | 127.0.0.1:16379 |

### 1.2 根因链（已逐项取证）

1. **tinker 实测：两套栈的 PostgreSQL 都连通**（`DB::select('select 1')` → `DB-OK`）。数据库连接字符串、网络、端口映射全部正常 → "连不上数据库"的说法不成立，真凶是 Redis。
2. **崩溃日志**（`docker logs geoflow-laravel-ai-quality-queue-1`）：
   ```
   RedisException: ERR AUTH <password> called without any password configured
   for the default user. Are you sure your configuration is correct?
     at PhpRedisConnector.php:111  ← WorkArticleAiQualityQueueCommand:43 → queue:work
   ```
   含义：客户端发了 AUTH，但 Redis 服务器**根本没设密码**。
3. **容器创建时间取证**（决定性证据）：
   - `geoflow-redis` 创建于 **09-09 10:08**，创建时环境变量 `REDIS_PASSWORD=`（**空**）
   - `geoflow-laravel-prod-redis-1` 创建于 **09-09 11:05**，创建时 `REDIS_PASSWORD=GpoJQ0i5...`（正常）
   - 而两套栈**挂载同一个宿主机文件** `D:\Documents\恒佳geo\GEOFlow-main\.env`，其中 `REDIS_PASSWORD="GpoJQ0i5..."` 已设置
   - 结论：09-09 上午 10:08 启动开发栈时 `.env` 还没写入 Redis 密码 → 开发栈 Redis 以无密码模式创建；一小时后写入密码并启动生产栈 → 生产栈正常。**开发栈从那以后没重建过**，其 6 个队列容器就一直在"发密码 ↔ 服务器不认"之间崩溃重启。
4. 次要发现：
   - **配置耦合**：dev 和 prod 共享同一个 `.env` 与同一个 `storage/` 目录挂载，改一处影响两套栈。
   - **prod 的 PG/Redis 无宿主机端口映射**：宿主机上直连 `127.0.0.1:5432` 或用 `DB_HOST=postgres` 跑 artisan 必然失败（`postgres` 是容器网络内的主机名，宿主机解析不到）。这是设计使然，不是故障；宿主机侧操作请进容器执行。
   - `docker-compose.yml` 的 redis 服务**没有** `REDIS_PASSWORD` 环境变量与 `--requirepass` 命令（prod 版有），即使现在重建开发栈，密码不匹配依旧会复现——必须先对齐 compose。

### 1.3 修复方案

**方案 A（推荐，一次性根治）**：把 `docker-compose.yml` 的 redis 服务对齐 prod 写法，然后重建：

```yaml
  redis:
    image: ${REDIS_IMAGE:-redis:8-alpine}
    container_name: geoflow-redis
    environment:
      REDIS_PASSWORD: "${REDIS_PASSWORD:-}"
    command:
      - /bin/sh
      - -lc
      - |
        if [ -n "$${REDIS_PASSWORD:-}" ]; then
          exec redis-server --appendonly yes --requirepass "$${REDIS_PASSWORD}"
        fi
        exec redis-server --appendonly yes
    ports:
      - "127.0.0.1:${REDIS_EXPOSE_PORT:-16379}:6379"
    restart: unless-stopped
```

```bash
cd /d/Documents/恒佳geo/GEOFlow-main
docker compose up -d redis --force-recreate
# 重启后 6 个崩溃的队列容器会在下一轮自动拉起并恢复正常
```

**方案 B（临时应急，不改文件）**：运行时给 dev Redis 补上密码（下次容器重建会丢失）：

```bash
docker exec geoflow-redis redis-cli CONFIG SET requirepass "GpoJQ0i5w2T9U6fXjzDSkHr0"
docker restart geoflow-laravel-ai-quality-queue-1 geoflow-laravel-ai-quality-queue-2 geoflow-queue geoflow-knowledge-queue geoflow-ai-quality-backfill-queue geoflow-ai-optimization-queue
```

**验证**：`docker ps` 中 6 个队列容器状态变为 `Up` 不再重启；`docker logs geoflow-queue --tail 5` 出现正常的心跳/任务输出。

**长期建议**：给开发栈单独一套 `.env.docker`（或 compose `env_file` 覆盖），避免与 prod 共享 `.env`。

---

## 第二部分：GEOFLOW 路由模块完整审计

审计范围：`routes/web.php`（745 行）、`routes/api.php`（182 行）、`routes/channels.php`、`routes/console.php`、`bootstrap/app.php`（路由注册/中间件别名/异常渲染），并交叉核对了 8 个关键控制器与服务的参数校验。全站 419 条路由在 prod 容器内编译通过（`route:list` 无错误）。

### 2.1 问题清单

| 编号 | 严重度 | 位置 | 问题 |
|---|---|---|---|
| R1 | 🟡 P2 | `routes/web.php:710` | `security-settings/password` 修改密码路由**没有限流**。同类操作 `account/password` 挂了 `throttle:admin-sensitive`（5 次/分钟），此处裸奔 |
| R2 | 🟡 P2 | `routes/web.php:244-245,368,404-410` 等 | 多处 `{id}` 参数**缺 `whereNumber()` 约束**且控制器方法签名是 `int`、未开 `strict_types`：访问 `/tasks/abc/toggle-status` 会命中路由后在参数绑定阶段抛 `TypeError` → **500 而不是 404**。同组的 `restore`（246 行）反而有约束，属于遗漏而非设计 |
| R3 | 🟡 P2 | `routes/api.php:71-124` | `catalog`、`tasks` 全组、`jobs/{job}`、`materials` 全组**完全没有 throttle**（其他所有 API 组都有 30~120/min 限流）。持有合法 token 的调用方可无限频请求，直接打 DB |
| R4 | 🟢 P3 | `routes/web.php:689-700` vs `702-709` | 敏感词功能存在**两组重复路由**（`site-settings.sensitive-words.*` 与 `security-settings.words.*`）指向同一控制器方法；后者 `wordId` 无 `whereNumber` 约束。双入口容易日后只改一处造成行为漂移 |
| R5 | 🟢 P3 | `routes/web.php:110` | `locale/{locale}` 无 `whereIn` 约束。控制器有 `isSupportedLocale` 兜底回退 zh_CN，**无安全风险**，仅记录 |
| R6 | 🟢 P3 | `routes/channels.php:6-8` | `App.Models.User.{id}` 私有频道使用默认 `web` guard，而本系统用户全部走 `admin` guard → 该频道**永远无法授权通过**，属死代码；且未限定模型类型，若未来引入普通 web 用户会误开放 |
| R7 | 🟢 P3 | `bootstrap/app.php:51-61` | `trustHosts` 的 root_domain 模式 `^[^.]+\.域名$` 配合 `subdomains: false`，**只允许一级子域名**；若未来托管 `a.b.example.com` 形式的站点会被 404。当前单级子域名不受影响 |

### 2.2 问题分析与修复建议

**R1 — 补限流（10 分钟）**
```php
Route::post('password', [SecuritySettingsController::class, 'updatePassword'])
    ->middleware('throttle:admin-sensitive')   // ← 新增
    ->name('password.update');
```
验证：连续 6 次提交错误旧密码，第 6 次（5/min 限额后）应返回 429。

**R2 — 补约束（30 分钟，涉及 4 处）**
```php
// web.php:244-245
Route::post('{taskId}/toggle-status', ...)->whereNumber('taskId');
Route::post('{taskId}/delete', ...)->whereNumber('taskId');
// web.php:368
Route::put('{articleId}', ...)->whereNumber('articleId');
// categories 组（404-410）全部 {categoryId} 路由补 ->whereNumber('categoryId')
```
验证：`curl -I /geo_admin/tasks/abc/toggle-status`（已登录会话）应由 500 变 404。

**R3 — API 补限流（20 分钟）**
```php
// api.php：给 catalog/tasks/jobs/materials 的 GET 组统一挂
->middleware(['api.scope:...', 'throttle:120,1']);
// 写操作组挂 'throttle:60,1'（与 articles 写路径对齐）
```
验证：1 分钟内连续 130 次 `GET /api/v1/tasks`，第 121 次应返回 429 + `retry_after`。

**R4 — 合并入口**：保留 `site-settings.sensitive-words.*`（有 whereNumber 的那组），`security-settings` 组内改为 302 重定向到前者；或至少给 `words.delete` 补 `whereNumber('wordId')`。

**R6**：直接删除死频道，或改为 `['guards' => ['admin']]` 并把 `$user` 类型标注为 `Admin`。

**R7**：暂不动；新增二级子域名托管需求时把模式改为 `^[^.]+(\.[^.]+)?\.域名$`。

### 2.3 审计中验证为"无问题"的关键点（排除误报）

- **前台 catch-all 资产路由**（`web.php:76`）：`HostedAssetController` 有完整的路径穿越防护（NUL 字节、反斜杠、`..` 段、realpath 前缀双重校验）——**安全**。
- **主题预览 `sitePath = .*`**（`web.php:650`）：控制器内用严格正则（`\A...\z` + `D` 修饰符）白名单校验——**安全**。
- **API `materials/{type}` 无路由约束**：`MaterialLibraryService` 内部有 `MATERIAL_TYPES` 白名单（404 兜底）——**安全**。
- **`$adminRoute->block(30, 30)`**（`web.php:743`）：是 Laravel 框架真实方法（`Route::block`，并发锁），非误用。
- **异常处理链**：`ApiException → 统一 JSON 信封`、`ThrottleRequestsException → 429 + Retry-After`、后台 404 友好视图、Host 头异常 → 404 + noindex——闭环完整。
- **登录路由**：web 与 API 的 login 均挂了 `throttle:admin-login`（30/min/IP）——安全。
- **`redirectUsersTo`** 显式指向 `admin.dashboard`，修复了框架默认回退到 `/` 的行为——正确。

### 2.4 建议执行顺序

1. 先执行第一部分方案 A（dev Redis 修复）——这是当前唯一"正在发生"的故障
2. R1 + R2 + R3 一次性修掉（合计约 1 小时，均为低风险小改动）
3. R4/R6/R7 放入日常维护队列
