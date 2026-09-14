# GEOFlow 全量修复报告（P0 + P1 + P2 + Python 脚本重写）

日期：2026-09-11 ｜ 分支：prod/v3.1.0-selfmedia ｜ 审计来源：`2026-09-12-code-audit-report.md`

## 修复总览

| 编号 | 严重度 | 问题 | 状态 | 处理方式 |
|---|---|---|---|---|
| B6 | P0 | 小红书/公众号/知乎不在 SELF_MEDIA_PLATFORMS | ✅ 已修 | 白名单 11→14 项 |
| B7 | P0 | streamDownload 失败返回 200+0 字节 | ✅ 已修 | 三道防线抛 ApiException 410 |
| B8 | P0 | stale claim 永不回收 | ✅ 已修 | 新命令 + 每 5 分钟调度 |
| B1 | P1 | claim() EXISTS 包装锁语义差异 | ✅ 已修 | count + lockForUpdate 实锁 |
| B2 | P1 | 同 Chrome 一次一条硬编码 | ✅ 已修 | config 可配置，默认 1 |
| B9 | P1 | 分发行卡死不自愈 | ✅ 已修 | 新自愈命令 + 调度（$tries=1 保留，见下） |
| B10 | P1 | FAILED→READY 无限重试 | ✅ 已修 | reopen 次数上限（默认 5） |
| B11 | P1 | 质检 TOCTOU 无锁 | ✅ 已修 | 文章行 lockForUpdate |
| B3 | P2 | 幂等无锁 | ✅ 核实无缺陷 | DB 已有 UNIQUE(idempotency_key, route_key) + insertOrIgnore，无需改 |
| B4 | P2 | 时区不明 | ✅ 核实无缺陷 | config/app.php 已是 Asia/Shanghai |
| B5 | P2 | transaction 3 次重试副作用 | ✅ 核实无缺陷 | 现有重试闭包均为纯 DB 操作，无外部副作用 |
| PY-1~10 | — | Python 导出脚本 10 个 bug | ✅ 已重写 | 替代为 artisan 命令，原脚本作废 |

## 修改文件清单

**修改（4）**
- `app/Models/ManualPublicationAccount.php` — B6
- `app/Http/Controllers/Api/V1/BrowserManualPublicationController.php` — B7
- `app/Services/BrowserOperations/ManualPublicationBrowserService.php` — B1/B2/B11
- `app/Services/GeoFlow/ManualPublicationService.php` — B10
- `config/geoflow.php` — 新增 browser / manual_publications / distribution_recovery 三组配置
- `routes/console.php` — 两个新调度

**新增（3）**
- `app/Console/Commands/RecoverStaleBrowserClaimsCommand.php` — B8
- `app/Console/Commands/GeoFlowRecoverStuckDistributionsCommand.php` — B9 自愈
- `app/Console/Commands/GeoFlowExportJumeitongCommand.php` — 替代 Python 脚本

**修改（1）**
- `app/Jobs/ProcessArticleDistributionJob.php` — B9 设计说明注释

## B9 的重要更正

审计时建议把 `$tries=1` 调大 —— **深入读码后确认这是错误建议**：
`$tries=1` 防止框架级重试与领域级重试（DistributionRetryPolicy 重新入队）叠加导致**重复发布**。
真正的缺口是：队列崩溃后丢失延迟任务时，queued/sending 行永久卡死。修复 = 补自愈命令，不是改 tries。

## 新增配置项（config/geoflow.php，全部有 env 覆盖）

```env
GEOFLOW_BROWSER_MAX_CONCURRENT_CLAIMS=1   # 同一浏览器 token 并发持有工作单上限
GEOFLOW_MAX_MANUAL_PUBLICATION_REOPENS=5  # 工作单 reopen 次数上限
GEOFLOW_DISTRIBUTION_QUEUED_DEBOUNCE_MINUTES=10
GEOFLOW_DISTRIBUTION_SENDING_STALE_MINUTES=30
```

## 验证命令（docker/PG 启动后执行）

```bash
# 1. 语法（已全部通过 php -l）
php artisan list | grep -E "recover-browser-claims|recover-stuck-distributions|export-articles-doc"

# 2. B8 验证：dry-run 看候选，不写库
php artisan geoflow:recover-browser-claims --dry-run

# 3. B9 验证
php artisan geoflow:recover-stuck-distributions --dry-run

# 4. B6 验证（tinker）：不再抛 DomainException
php artisan tinker --execute="var_dump(in_array('xiaohongshu', App\Models\ManualPublicationAccount::SELF_MEDIA_PLATFORMS));"

# 5. B7 验证：对一个 media 缺失的工作单请求，应得到 410 JSON 而非 200 空体
curl -i -H "Authorization: Bearer <token>" http://127.0.0.1:18080/api/v1/browser/manual-publications/<id>/media/<key>

# 6. 导出验证（替代 Python 脚本）
php artisan geoflow:export-articles-doc --limit=5
php artisan geoflow:export-articles-doc --ids=1,3,5 --out=D:/Documents/恒佳geo/jumeitong-helper/已导出
```

## 遗留事项

- 本机验证被 DB 连接阻塞：`.env` 的 `DB_HOST=postgres` 是 docker 网络名，docker Desktop 未启动时所有 artisan 命令无法触库。需先起 docker 或临时改 `DB_HOST=127.0.0.1`。
- B2 并发上限默认保持 1；切到自动化 worker 时才建议调大，并确认扩展端支持并行。
