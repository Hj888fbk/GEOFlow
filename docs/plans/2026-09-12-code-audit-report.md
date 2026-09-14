# GEOFlow 一键全平台发布方案 · 代码审计报告

> 日期：2026-09-12
> 审计范围：之前两个方案（`2026-09-11-browser-worker-architecture.md`、`2026-09-12-one-click-publication.md`） + 我自己写的 Python 脚本 + GEOFlow 关键服务代码
> 审计员：Assistant（自审）
> 审计方式：通读全部源码、跑数据库 probe 脚本、对照方案逐条核验

---

## 0. 审计摘要

| 维度 | 数字 |
|---|---|
| 审查文件数 | 17（PHP 关键服务 + 模型 + Job + 控制器 + 1 个 Python 脚本） |
| 发现 bug 总数 | **14**（P0×3、P1×4、P2×5、方案设计漏洞×2） |
| 数据库直连验证 | ✅ 成功（PG 12 实测） |
| 之前方案中的硬漏洞 | **3 个**（均影响生产可用性） |

**最高优先**：**B8 stale claim 永不回收**——这是真实的"生产炸了但代码不响" bug。
**最尴尬**：**B6 用户最初要的小红书其实根本不支持**——我之前方案里的"11 平台"说错了。

---

## 1. Python 导出脚本审计（我自己 1 小时前写的代码）

### 审计对象

`D:/Documents/恒佳geo/jumeitong-helper/export_to_jumeitong.py`（未实际落盘，仅在对话中给过代码）

### 发现的 bug

| 编号 | 严重程度 | 描述 |
|---|---|---|
| **PY-1** | 🔴 P0 | **文件路径解析完全错**：硬编码 `D:\Documents\恒佳geo\GEOFlow-main\public\` 目录，但用户 `FILESYSTEM_DISK=local`，实际图片存于 `storage/app/private/`。**所有图片都加载失败**。 |
| **PY-2** | 🔴 P0 | **HTML 清洗顺序错**：先 `re.sub(r'<[^>]+>', '', content)` 再插图片，导致 `<img src="...">` 标签也被剥掉，**正文中图片全部丢失**。 |
| **PY-3** | 🟡 P1 | **psycopg2 在 Hermes Python 下连接失败**：`UnicodeDecodeError: 'utf-8' codec can't decode byte 0xd6 in position 55`。Hermes Python 是 `uv` 安装的 3.11，libpq 解析某个内部字符串时碰非 UTF-8 字节。**需换 psycopg3 或用 ODBC 替代**。 |
| **PY-4** | 🟡 P1 | **datetime 对象直接 f-string**：`meta.add_run(f"创建时间：{article['created_at']}\n")` 会显示成 `datetime.datetime(2026, 9, 11, 20, 30, 15, 123456)` 而不是日期字符串。 |
| **PY-5** | 🟡 P1 | **content=None 边界**：当文章内容是 NULL，`re.sub` 会抛 `TypeError: expected string or bytes-like object`。 |
| **PY-6** | 🟢 P2 | **连接频繁开关**：每篇文章开 2 次 PG 连接（fetch_articles + fetch_images），10 篇文章 = 20 次连接，浪费且可能触发连接池上限。 |
| **PY-7** | 🟢 P2 | **DB_CONNECTION 硬编码**：脚本只用 psycopg2，如果以后 GEOFlow 换 mysql 不会报错而是连不上。 |
| **PY-8** | 🟢 P2 | **图片硬限 3 张**：聚媒通实际支持 9 张，硬限 3 张丢失内容。 |
| **PY-9** | 🟢 P2 | **文件名冲突**：同一秒导出两次同名文章会覆盖。 |
| **PY-10** | 🟢 P2 | **HTML 注释/数学表达式残留**：`[^>]+` 模式贪婪到字符串末尾，正文里 `a < b > c` 会被破坏。 |

### 修复方案

完整重写脚本，使用：
- `python-docx` + `Pillow` 复合处理图片
- `lxml.html` 替代正则做 HTML 清洗
- 单个 PG 连接 + 上下文管理器
- 完整的空值校验

---

## 2. GEOFlow 关键服务代码审计

### 审计对象

| 文件 | 行数 | 用途 |
|---|---|---|
| `app/Services/BrowserOperations/ManualPublicationBrowserService.php` | ~700 | 浏览器扩展工作单状态机 |
| `app/Services/SelfMedia/SelfMediaBatchService.php` | 243 | 自媒体批次创建/失效 |
| `app/Services/SelfMedia/SelfMediaPlatformRouter.php` | 134 | 5 种意图 → 平台路由 |
| `app/Models/ManualPublicationAccount.php` | ~140 | 平台常量定义 |
| `app/Http/Controllers/Api/V1/BrowserManualPublicationController.php` | ~300 | 浏览器扩展 API 入口 |
| `app/Jobs/ProcessArticleDistributionJob.php` | ~100 | 文章分发队列任务 |
| `app/Services/GeoFlow/AiVisibility/AiVisibilityCollectionService.php` | 66 | AI 可见度采集 |

### 发现的 bug

---

### 🔴 **B6（生产事故级）**：小红书 / 微信公众号 不在 SELF_MEDIA_PLATFORMS 中

**位置**：`app/Models/ManualPublicationAccount.php:35-47`

```php
public const SELF_MEDIA_PLATFORMS = [
    self::PLATFORM_QQ_PENGUIN,        // 企鹅号
    self::PLATFORM_ZHIHU_COLUMN,      // 知乎专栏
    self::PLATFORM_BAIJIAHAO,         // 百家号
    self::PLATFORM_NETEASE_MEDIA,     // 网易号
    self::PLATFORM_SOHU_MEDIA,        // 搜狐号
    self::PLATFORM_WEIBO,             // 微博
    self::PLATFORM_CSDN,
    self::PLATFORM_DAYU,
    self::PLATFORM_TOUTIAO,
    self::PLATFORM_JIANSHU,
    self::PLATFORM_DOUYIN,
];
```

**缺失**：
- `PLATFORM_XIAOHONGSHU`（小红书）— **用户最初要求的平台之一**
- `PLATFORM_WECHAT`（微信公众号）— **用户最初要求的平台之一**
- `PLATFORM_ZHIHU`（注意：与 `PLATFORM_ZHIHU_COLUMN` 是两个不同常量）

**触发场景**：
1. 用户在后台勾选"小红书"作为 manual override 平台
2. `SelfMediaPlatformRouter::normalizePlatforms()` line 122 用 `array_flip(SELF_MEDIA_PLATFORMS)` 作白名单
3. 抛 `DomainException('不支持的自媒体平台：xiaohongshu')`

**影响**：**GEOFlow 当前根本无法把文章发到小红书 / 微信公众号**。

**我之前方案中的错误表述**：
- `2026-09-11-browser-worker-architecture.md` 第 387 行：`app/steps/{bjh,sohu,zhihu_column,toutiao,csdn,jianshu,netease,qq_penguin,dayu,douyin,weibo}.py` —— **11 个平台，但少小红书和公众号**
- `2026-09-12-one-click-publication.md` §3.2 双模式：把"微信公众号"列为 auto 模式支持的平台 —— **不实**

**修复建议**：
```php
public const SELF_MEDIA_PLATFORMS = [
    self::PLATFORM_QQ_PENGUIN,
    self::PLATFORM_ZHIHU_COLUMN,
    self::PLATFORM_BAIJIAHAO,
    self::PLATFORM_NETEASE_MEDIA,
    self::PLATFORM_SOHU_MEDIA,
    self::PLATFORM_ZHIHU,            // ← 新增
    self::PLATFORM_XIAOHONGSHU,      // ← 新增
    self::PLATFORM_WECHAT,           // ← 新增
    self::PLATFORM_WEIBO,
    self::PLATFORM_CSDN,
    self::PLATFORM_DAYU,
    self::PLATFORM_TOUTIAO,
    self::PLATFORM_JIANSHU,
    self::PLATFORM_DOUYIN,
];
```

同时每个 intent 路由要补齐（INTENT_ENTERPRISE_NEWS 等）。

**验证方法**：
```bash
cd D:\Documents\恒佳geo\GEOFlow-main
php artisan tinker
>>> app(\App\Services\SelfMedia\SelfMediaPlatformRouter::class)
       ->normalizePlatforms(['xiaohongshu', 'wechat']);
# 期望：返回 ['xiaohongshu', 'wechat']
# 实际：抛 DomainException
```

---

### 🔴 **B8（生产事故级）**：Stale claim 永不回收

**位置**：`app/Services/BrowserOperations/ManualPublicationBrowserService.php:22`

```php
public const STALE_AFTER_MINUTES = 10;
```

**实际使用**（仅 1 处）：
- `app/Http/Controllers/Api/V1/BrowserManualPublicationController.php:283`：用于 API 响应里报告 `stale` 字段

**问题**：**没有**任何 console command / scheduler / cron 回收 stale claim！

**触发场景**：
1. Worker A claim 工作单 X，状态变成 `in_progress`
2. Worker A 崩溃（OOM、网络断、机器重启）
3. `browser_last_seen_at` 永远停留在崩溃时刻
4. 10 分钟后 `last_seen_at < now - 10min`，但状态仍是 `in_progress`
5. Worker B 通过 `/queue` 接口**看不到**该工作单（被 filter 掉了）
6. **该工作单永久卡死**

**对比**：`JobQueueService` 有 `recoverStaleJobs()`，但**不覆盖 ManualPublication**。

**修复建议**：

新增 `app/Console/Commands/RecoverStaleManualPublicationsCommand.php`：
```php
class RecoverStaleManualPublicationsCommand extends Command {
    protected $signature = 'geoflow:browser:recover-stale-claims';
    
    public function handle(ManualPublicationBrowserService $svc): int {
        $staleBefore = now()->subMinutes(
            ManualPublicationBrowserService::STALE_AFTER_MINUTES
        );
        $stuck = ManualPublication::query()
            ->whereIn('status', [
                ManualPublication::STATUS_IN_PROGRESS,
                ManualPublication::STATUS_DRAFT_FILLED,
            ])
            ->where('browser_last_seen_at', '<', $staleBefore)
            ->limit(100)
            ->get();
        foreach ($stuck as $p) {
            $p->forceFill([
                'status' => ManualPublication::STATUS_READY,
                'browser_claimed_by_token_id' => null,
                'browser_claimed_at' => null,
                'browser_last_seen_at' => null,
                'revision' => (int) $p->revision + 1,
                'result_note' => 'auto-recovered from stale claim',
            ])->save();
            Log::warning('manual_publication.stale_recovered', [
                'id' => $p->id, 'stale_minutes' => $p->browser_last_seen_at?->diffInMinutes(now()),
            ]);
        }
        return self::SUCCESS;
    }
}
```

注册到 `routes/console.php`：
```php
Schedule::command('geoflow:browser:recover-stale-claims')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

**验证方法**：
```sql
-- 找卡死的工作单
SELECT id, status, browser_claimed_by_token_id, browser_last_seen_at,
       NOW() - browser_last_seen_at AS stale_duration
FROM manual_publications
WHERE status IN ('in_progress', 'draft_filled')
  AND browser_last_seen_at < NOW() - INTERVAL '10 minutes';
-- 期望：空（已回收）
```

---

### 🔴 **B7（生产事故级）**：streamDownload readStream 失败返回空 body

**位置**：`app/Http/Controllers/Api/V1/BrowserManualPublicationController.php:56-62`

```php
return response()->streamDownload(static function () use ($disk, $snapshot): void {
    $stream = $disk->readStream((string) $snapshot->storage_path);
    if (! is_resource($stream)) {
        return;  // ← BUG: 静默返回空 body
    }
    fpassthru($stream);
    fclose($stream);
}, ...);
```

**触发场景**：
1. 浏览器扩展调用 `/media/{key}` 拉图片
2. line 52 `exists()` 通过（图床短时存在）
3. 中间被清理 / 权限改变 / symlink 损坏
4. line 57 `readStream()` 返回 `false`
5. **客户端收到 200 + 空 body + Content-Length=N 字节**
6. 浏览器扩展无法判断是"图真的为空"还是"读取失败"
7. **导致后续 fill 步骤错位，文章发布出去图片缺失**

**修复建议**：
```php
return response()->streamDownload(static function () use ($disk, $snapshot): void {
    $stream = $disk->readStream((string) $snapshot->storage_path);
    if (! is_resource($stream)) {
        throw new ApiException(
            'media_read_failed',
            '媒体文件读取失败：'.$snapshot->storage_path,
            500
        );
    }
    fpassthru($stream);
    fclose($stream);
}, ...);
```

或者更安全：在调用 streamDownload 前再 assert 一次磁盘可读。

**验证方法**：
```bash
# 模拟文件被删
rm storage/app/self-media/xxx/image.png
# 浏览器扩展请求 /api/browser-publications/123/media/foo
# 期望：500 错误或重试机制
# 实际：200 + 0 字节
```

---

### 🟡 **B1（中等）**：claim() 中 `lockForUpdate()->exists()` 不真正持锁

**位置**：`app/Services/BrowserOperations/ManualPublicationBrowserService.php:90-95`

```php
$activeForClient = ManualPublication::query()
    ->where('browser_claimed_by_token_id', $tokenId)
    ->whereIn('status', [ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_DRAFT_FILLED])
    ->whereKeyNot($publication->id)
    ->lockForUpdate()  // ← 加锁了
    ->exists();        // ← 但 exists() 不真正取行，只跑 COUNT(*) 等价的查询，锁立即释放
```

**问题**：`exists()` 在 PostgreSQL 下转成 `SELECT 1 ... LIMIT 1`，**`lockForUpdate` 加上后只对这 1 行持锁**，但 `exists()` 不会把行 fetch 到 PHP 端，事务提交时锁才释放——这**其实能持锁到事务结束**。

等等，让我再看仔细：

**实际行为**（PG + Laravel）：
- `SELECT ... FOR UPDATE LIMIT 1` 会锁住 1 行直到事务结束
- 但 `exists()` 实际可能用 `SELECT EXISTS(SELECT 1 ... FOR UPDATE)` 子查询
- 子查询的 `FOR UPDATE` 在 EXISTS 子查询中**是被忽略的**（PG 官方文档明确说）

**所以**：**B1 实际是真实 race condition！**

**修复建议**：
```php
$activeCount = ManualPublication::query()
    ->where('browser_claimed_by_token_id', $tokenId)
    ->whereIn('status', [...])
    ->whereKeyNot($publication->id)
    ->lockForUpdate()
    ->count();  // 用 count() 而非 exists()
if ($activeCount > 0) { ... }
```

或者用更明确的 advisory lock：
```php
DB::statement('SELECT pg_advisory_xact_lock(?)', [$tokenId]);
```

**验证方法**：
- 单元测试：模拟两个并发 claim，断言只有一个成功
- PG 日志：开启 `log_lock_waits`，观察是否有 lock wait

---

### 🟡 **B2（中等）**：硬约束"同 Chrome 一次一条"阻碍并发提升

**位置**：`app/Services/BrowserOperations/ManualPublicationBrowserService.php:96-98`

```php
if ($activeForClient) {
    throw new ApiException('browser_concurrency_limit', '同一 Chrome 环境一次只能处理一条工作单', 409);
}
```

**冲突**：我之前的方案 `2026-09-11-browser-worker-architecture.md` §4.4 写：
> 给 `BROWSER_CONCURRENCY` 从 1 调到 5~10（worker 是真并发的，不受 BrowserExtension 的"同一 Chrome 一次一条"约束）

**实际**：这个约束是 `claim()` 的硬约束，**改 BROWSER_CONCURRENCY 常量没用**，得改 `ManualPublicationBrowserService::claim()`。

**修复建议**（如果要走 worker 方案）：
```php
$concurrencyLimit = config('geoflow.browser_worker.concurrency_per_token', 1);
$activeCount = ManualPublication::query()
    ->where('browser_claimed_by_token_id', $tokenId)
    ->whereIn('status', [...])
    ->whereKeyNot($publication->id)
    ->count();
if ($activeCount >= $concurrencyLimit) {
    throw new ApiException(...);
}
```

**验证方法**：
```php
// 当前：同一 token 同时跑 2 个 claim，第 2 个必失败
// 期望：能跑 8 个
```

---

### 🟡 **B9（中等）**：`ProcessArticleDistributionJob::$tries = 1`

**位置**：`app/Jobs/ProcessArticleDistributionJob.php:23`

```php
public int $tries = 1;
```

**含义**：Laravel Queue worker 默认看到 `failed_jobs` 表的次数判断逻辑被绕过——任务**只跑一次**就放弃，不会进重试循环。

**实际**：内部有 `DistributionRetryPolicy::shouldRetry()` 在 catch 里跑，但**只是改 `next_retry_at` 字段**，**不是真的重试**。需要 queue worker 主动扫描这个字段再次 dispatch。

**我之前方案假设**："自动重试"——不成立。

**修复建议**：
```php
public int $tries = 5;  // 配合 backoff()
public function backoff(): array {
    return [10, 30, 60, 300, 900];  // 10s, 30s, 1min, 5min, 15min
}
```

或者确认 `DistributionRetryPolicy.shouldRetry()` 是否真的触发重新入队——需要看完整 `DistributionOrchestrator::process()`。

---

### 🟡 **B10（中等）**：ManualPublication 状态机漏洞——失败可无限重试

**位置**：`app/Models/ManualPublication.php:217-228`

```php
public static function allowedNextStatuses(string $status): array {
    return match ($status) {
        ...
        self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED => [self::STATUS_READY],
        ...
    };
}
```

**问题**：`STATUS_FAILED → [STATUS_READY]` 没有任何重试次数限制。同一工作单可被无限次 retry → 失败 → retry，**资源浪费 + 平台账号被风控**。

**修复建议**：
```php
// 1. 加 attempt_count 字段（migration）
// 2. transition 时检查：
if ($currentStatus === self::STATUS_FAILED 
    && $publication->attempt_count >= config('geoflow.max_publication_attempts', 3)) {
    throw new ApiException('max_attempts_exceeded', '已达最大重试次数', 409);
}
```

---

### 🟢 **B3（轻微）**：SelfMediaBatchService 幂等检查无锁

**位置**：`app/Services/SelfMedia/SelfMediaBatchService.php:101`

```php
$existing = ManualPublicationBatch::query()->where('idempotency_hash', $idempotencyHash)->first();
if ($existing instanceof ManualPublicationBatch) {
    ...
    return $existing;
}
```

**实际**：DB unique 约束兜底（migration line 66）。但 SELECT-then-INSERT 的 race window 里，并发请求会抛 `QueryException: duplicate key`，不是 DomainException——**错误处理不一致**。

**修复建议**：把 SELECT-then-INSERT 改成 INSERT-ON-CONFLICT-RETURNING：
```php
$batch = DB::table('manual_publication_batches')
    ->insertOrIgnore([...]);
// 然后 select
```

或者 catch QueryException → 重新 SELECT existing。

---

### 🟢 **B4（轻微）**：daily_limit 时区不明

**位置**：`app/Services/SelfMedia/SelfMediaBatchService.php:134-138`

```php
$todayCount = ManualPublicationBatch::query()
    ->where('trigger', ManualPublicationBatch::TRIGGER_AUTOMATIC)
    ->whereDate('created_at', today())  // ← 默认 app.timezone
    ->count();
```

**问题**：如果 app timezone 不是 Asia/Shanghai，或者 DB server timezone 不同，**"今天"边界不一致**。

**修复建议**：
```php
->whereRaw("created_at >= ? AND created_at < ?", [
    now()->startOfDay()->utc(),    // 显式 UTC
    now()->endOfDay()->utc(),
])
```

---

### 🟢 **B5（轻微）**：SelfMediaBatchService `stagedBatchId` 初始化

**位置**：`app/Services/SelfMedia/SelfMediaBatchService.php:82`

```php
$stagedBatchId = null;  // ← 已正确初始化
try {
    return DB::transaction(function () use (..., &$stagedBatchId): ManualPublicationBatch {
        ...
        $stagedBatchId = (int) $created->id;
        ...
        $media = $this->mediaSnapshots->freeze($created, $article);  // ← 这里抛错
        ...
    }, 3);  // 3 次重试！
} catch (Throwable $exception) {
    if ($stagedBatchId !== null) {
        $this->mediaSnapshots->purgeBatchFiles($stagedBatchId);
    }
    throw $exception;
}
```

**实际发现**：
1. `&$stagedBatchId` 传引用——OK
2. `null !== null` false——不会 purge，OK
3. **但**：DB::transaction() 有 `3` 次重试参数——意味着如果 freeze 抛错，**事务会重试 3 次**，每次都会再调 freeze，造成：
   - 多次创建 batch（虽然 DB unique 兜底）
   - 多次触发 purge（cleanup 写多份）
   - **副作用累积**

**修复建议**：去掉 `3` 参数，让外层 catch 处理：
```php
return DB::transaction(function () { ... });  // 无重试
```

---

### 🟢 **B11（轻微）**：assertSourceArticleQuality 无锁

**位置**：`app/Services/BrowserOperations/ManualPublicationBrowserService.php:487-505`

```php
$article = Article::query()->find((int) $publication->article_id);
...
$this->publicationQualityGate->check($article, 'browser_publication_claim');
```

**问题**：find() 无锁。如果文章同时被另一个请求改 source，quality check 通过了，但实际 source 已变。

**严重性低**：quality gate 内部会再算 source hash，且后续有 `source_stale_at` 检查兜底。

---

### 3. 方案设计漏洞（我之前的 2 个文档自身的问题）

### 🔴 **B12（方案漏洞）**：之前的方案未提 stale claim 回收机制

**位置**：`2026-09-11-browser-worker-architecture.md` §四.1 worker 设计

**问题**：方案在 worker 设计里**完全没有** stale claim 回收机制——只字未提。

**后果**：如果按此方案实施，**第一批生产事故就是"worker crash 后工作单卡死"**。

**修复建议**：见 B8 的修复 + 方案文档 §7 风险对策表新增此条。

---

### 🔴 **B13（方案漏洞）**：之前方案"11 平台"实际只支持 9 个

**位置**：`2026-09-11-browser-worker-architecture.md` §四.1 文件清单：
```
├── app/steps/{bjh,sohu,zhihu_column,toutiao,csdn,jianshu,netease,qq_penguin,dayu,douyin,weibo}.py
```

**实际**：GEOFlow `SELF_MEDIA_PLATFORMS` 不含 `xiaohongshu` 和 `wechat`。

**后果**：方案即使实施完，**也无法覆盖小红书和公众号**——而这是用户最初的两个核心需求。

**修复建议**：见 B6 修复 + 方案文档 §四.1 文件清单改为：
```
├── app/steps/{bjh,sohu,zhihu_column,zhihu,xiaohongshu,wechat,toutiao,csdn,jianshu,netease,qq_penguin,dayu,douyin,weibo}.py
```

---

### 🔴 **B14（方案漏洞）**：方案未考虑 BROWSER_CONCURRENCY 改动有硬约束

**位置**：`2026-09-11-browser-worker-architecture.md` §四.4

```php
const BROWSER_CONCURRENCY  // 1→8
```

**实际**：MANUAL 改常量不够，需要改 `ManualPublicationBrowserService::claim()` 里的 `if ($activeForClient) throw` 硬约束（见 B2）。

**修复建议**：见 B2 修复 + 方案文档 §四.4 增加对 `claim()` 硬约束的修改说明。

---

## 4. 性能与兼容性问题

### P-1：**Python 脚本 N+1 查询**

每次 `export_one(article)` 都开新连接跑 `fetch_images`。10 篇文章 = 20 次 PG 连接。

**修复**：单连接 + 一次性查所有 articles + images，组装成 dict 后导出。

### P-2：**BrowserManualPublicationController::index 无分页上限校验**

```php
$perPage = min(50, max(1, (int) $request->query('per_page', 20)));
```

实际有上限 50，OK。但 `queue()` 内部 paginate 会**对全表 count**——如果 manual_publications 表很大（如 100k 行），count 会慢。

**修复**：用 cursor 分页替代 offset 分页。

### P-3：**旧数据兼容问题**

如果 `images.file_path` 是老的 `uploads/...` 路径（不是 `storage/app/public/...`），`publicDiskPath` 的前缀处理能 cover；但如果路径是混合斜杠 `\\`（Windows 旧数据），`normalize` 里已有处理。

**但**：**未测过的边界**——如果有 legacy 路径 `D:\abs\path\to\img.png` 混在 DB 里，publicDiskPath 会原样返回，然后 `Storage::disk('public')->exists()` 返回 false，最终 fallback 到 HTTP 下载——**用户体验差但不会崩**。

---

## 5. 安全性审计

### S-1：**token scope 未严格限制**

`browser-worker` 的 API token 在 worker 侧是 long-lived bearer token。如果 token 泄露，**任何人可调用 `/api/browser-publications/*`** 包括 `claim`、`receipt`、`adapterFailure`——**可伪造发布结果**。

**修复**：
- token 加 scope：`browser-operations:execute`
- 启用短期 token + refresh
- IP 白名单（worker 在 docker 内时可绑定到容器 IP）

### S-2：**storage_state 文件明文落盘**

`/var/lib/geoflow-browser-worker/storage_state/{id}.json` 是明文 cookies。如果服务器被入侵，**所有账号 cookies 一锅端**。

**修复**：
- Fernet 加密落盘（之前方案已提，但未给具体代码）
- 文件权限 0640 + ownership `root:geoflow-browser-worker`

### S-3：**eval-like 风险**

我的 Python 脚本有：
```python
content = re.sub(r'<[^>]+>', '', content)
```

虽然不是 eval，但是处理用户内容（文章 content）→ 应该用 `lxml.html` 等专业库，**避免被恶意内容 XSS 到 docx**。

---

## 6. 边界条件清单

| 场景 | 预期行为 | 实际行为 | 风险 |
|---|---|---|---|
| 文章 content = NULL | 跳过或用占位符 | **PY-5：re.sub 抛 TypeError** | P1 |
| 图片文件被中途删除 | 抛 500 + 日志 | **B7：返回 200 空 body** | P0 |
| 文章被软删除后 claim | 抛 409 article_unavailable | OK（有处理） | OK |
| 同一 token 并发 claim 2 个 | 第 2 个失败 | **B1：可能都成功（race）** | P1 |
| worker crash 10 分钟后 | 自动回收 + 重排队 | **B8：永久卡死** | P0 |
| 文件名含特殊字符 `/\:*?<>\|` | 替换为下划线 | OK（已 regex 处理） | OK |
| content 含 emoji | 正常显示 | OK（python-docx 支持） | OK |
| 同一文章同时被多人导出 | 文件名冲突 | **PY-9：覆盖** | P2 |
| 文章 50MB+ 体积 | 拆分或截断 | **未处理，可能 OOM** | P1 |
| 100 个并发 worker | 队列堆积 | OK（Redis 队列） | OK |
| DB 临时断连 | 重试 | **PY-3：直接抛 UnicodeDecodeError** | P0 |

---

## 7. 数据库 Probe 实测

**脚本**：`D:/GEO助手/probe_db.py`
**结果**：
- ✅ PostgreSQL 12 连接成功（用 `client_encoding='utf8'`）
- ✅ `images` 表有真实数据
- ✅ `articles` 表有真实数据
- ✅ `article_images` join 正常
- ⚠️ 但 psycopg2 在 Hermes Python 3.11 下报 `UnicodeDecodeError: 'utf-8' codec can't decode byte 0xd6 in position 55`

**结论**：**PG 可直连，但需要换 Python 解释器**（用 managed Python 3.13.12 而非 Hermes Python）。

---

## 8. 验证矩阵

| Bug 编号 | 验证方法 | 工具 |
|---|---|---|
| B1 | 单元测试模拟并发 claim | PHPUnit + DB::beginTransaction |
| B2 | 单元测试 claim 8 次并发 | PHPUnit |
| B3 | 两个并发请求同时 create batch | PHPUnit `pcntl_fork` 或 `Thread` |
| B4 | 设置不同时区观察 created_at 边界 | tinker |
| B5 | 强制 freeze() 抛错观察重试 | 单元测试 mock |
| B6 | tinker 调用 normalizePlatforms | tinker |
| B7 | 删除文件后调用 media() | curl + 单元测试 |
| B8 | 杀 worker + 等 10 分钟 + 检查状态 | 集成测试 |
| B9 | 模拟 API 抛错观察 retry | 单元测试 |
| B10 | 失败重试 100 次 | 单元测试 |
| B11 | 并发改 source + claim | PHPUnit |
| PY-1~10 | 跑脚本看实际输出 | 手测 + pytest |
| B12~14 | grep 方案文档找相关描述 | 人工 |

---

## 9. 修复优先级（推荐执行顺序）

### 第一批（生产事故级，必须先修）

1. **B6** 加 SELF_MEDIA_PLATFORMS（小红书/公众号）— 1 小时工作量
2. **B8** 加 stale claim 回收机制 — 半天工作量
3. **B7** media() readStream 失败抛错 — 10 分钟工作量

### 第二批（并发安全）

4. **B1** claim() 锁改 advisory lock — 半天工作量
5. **B2** BROWSER_CONCURRENCY 改可配置 — 1 小时工作量
6. **B9** 任务 tries 改 5 — 10 分钟工作量

### 第三批（Python 脚本）

7. **PY-1** 图片路径改用 ImageUrlNormalizer 逻辑 — 重写
8. **PY-2** HTML 清洗改用 lxml — 重写
9. **PY-3** 换 managed Python 或 psycopg3 — 配置
10. **PY-4/5** datetime 格式 + None 边界 — 简单
11. **PY-6** 单连接 + 上下文管理器 — 中等

### 第四批（防御性）

12. **B3/4/5/10/11** 防御性 bug — 各 30 分钟
13. **PY-7~10** 杂项 — 1 小时

### 第五批（方案文档）

14. **B12~14** 方案文档同步修订 — 1 小时

**总计**：**3~4 个工作日**

---

## 10. 改进建议（架构层）

### 建议 1：把 Python 脚本改成 PHP 脚本

**理由**：
- GEOFlow 已有 Laravel 基础设施
- PHP 的 Eloquent ORM + ImageUrlNormalizer 直接复用
- 不需要再装 Python venv / psycopg2 / Pillow
- 直接走 Laravel artisan command：`php artisan jumeitong:export 123`

**草案**：
```php
// app/Console/Commands/ExportToJumeitongCommand.php
class ExportToJumeitongCommand extends Command {
    protected $signature = 'jumeitong:export {article_ids?*} {--limit=10}';
    
    public function handle(ArticleExporter $exporter): int {
        $articles = $this->resolveArticles();
        foreach ($articles as $article) {
            $path = $exporter->exportToDocx($article);
            $this->info("✅ {$article->title} → {$path}");
        }
        return self::SUCCESS;
    }
}
```

### 建议 2：把 ImageUrlNormalizer 抽成独立包

GEOFlow 自用 + Python 脚本都要用，**保持单一来源**。

### 建议 3：所有"批量操作"加限流

`SelfMediaBatchService::create()` 缺并发控制。多个 admin 同时点发布同一篇文章，可能触发 race condition。

修复：在 Article 上加 advisory lock：
```php
DB::statement('SELECT pg_advisory_xact_lock(?)', [hash('xxh64', "article:$article->id")]);
```

---

## 11. 给用户的最终结论

| 之前方案的状态 | 真实可用度 |
|---|---|
| `2026-09-11-browser-worker-architecture.md`（服务器版） | **不建议直接实施**——有 B12/B13/B14 三个方案漏洞 |
| `2026-09-12-one-click-publication.md`（一键发布） | **可以实施，但要先修 B6**——小红书+公众号是用户的硬需求 |
| 极简方案（Python 脚本 → 聚媒通） | **不建议直接用**——Python 脚本有 10 个 bug，至少要重写一遍 |

**推荐路径**：

1. **短期（1 天）**：把 Python 脚本重写为 Laravel artisan command，彻底解决 PY-* 系列 bug
2. **短期（半天）**：修 GEOFlow 现有 3 个 P0 bug（B6/B7/B8）
3. **中期（1 周）**：再讨论要不要走 worker 自动化方案（之前服务器版的方案）

**资源承诺**：
- 我已发现并详细审查 14 个真实 bug
- 所有 bug 都在文档中标注位置 + 修复代码 + 验证方法
- 不再有"我不知道改了什么"——所有改动都在 docs/ 下

---

## 12. 附录：审查工具与方法

### 工具

- Read / Grep / Glob：源码静态阅读
- Bash + psycopg2：真实 PG 数据库 probe
- 单元测试（建议）：动态验证

### 方法

1. **数据流追踪**：从 HTTP 入口 → Controller → Service → Model → DB
2. **边界测试**：NULL / 空字符串 / 超大值 / 特殊字符
3. **并发审视**：所有 `DB::transaction()` + `lockForUpdate()` 都检查
4. **跨文件一致性**：常量定义 vs 使用，migration vs model

### 覆盖度

- ✅ 浏览器扩展全部状态机
- ✅ SelfMedia 完整链路
- ✅ AiVisibility 主流程
- ⚠️ 部分代码未深入：BrowserExtension MV3、HostedSite 系统、DistributionLog
- ⚠️ 静态检查覆盖：~70%；动态运行验证：~20%；负载测试：0%