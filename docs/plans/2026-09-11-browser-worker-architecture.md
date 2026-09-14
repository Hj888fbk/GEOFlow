# GEOFlow 服务端自动发布改造方案

> 日期：2026-09-11
> 范围：`prod/v3.1.0-selfmedia` 分支（`GEOFlow-main/`）
> 终点：让 GEOFlow **自己**完成"自动打开目标平台、登录、填充、最终发布、回收回执"全链路，彻底替代 Chrome 浏览器扩展的人工最后一步

---

## 一、现状盘点（基于已读完的代码）

### 1.1 自动化已覆盖的部分

| 维度 | 实现位置 | 状态 |
|---|---|---|
| 自媒体 API 直发 | `app/Services/GeoFlow/ByxxApiPublisher.php`、`WordPressRestPublisher.php`、`GenericHttpApiPublisher.php` | ✅ 通过 `DistributionOrchestrator` + `ProcessArticleDistributionJob` 全自动 |
| AI 可见度（API） | `app/Services/GeoFlow/AiVisibility/AiVisibilityCollectionService.php`（豆包 Ark、Search Custom、DeepSeek） | ✅ `CollectAiVisibilityKeywordJob` + `DetectAiVisibilityCompetitorsJob` |
| 自媒体任务调度 | `SelfMediaBatchService` + `SelfMediaPlatformRouter`（11 平台确定性路由 + 5 种内容意图） | ✅ 自动创建批次与工作单 |
| 队列基建 | `docker-compose.prod.yml` 的 `queue` 服务（监听 `system-updates,geoflow,distribution,theme-replication,self-media,default`） | ✅ Laravel Queue + Redis + Horizon |

### 1.2 仍依赖人工的部分

| 维度 | 实现位置 | 卡点 |
|---|---|---|
| 11 个 C 端平台最终发布 | `browser-extension/`（MV3）+ `ManualPublicationBrowserService` | 扩展**永不点击 Publish 按钮**（设计文档明示），必须人工 |
| 网页版 AI 平台（Kimi、智谱、文心一言、Gemini、Claude 网页 UI 等） | 目前**没有 worker**，只能手工浏览器查 | GEOFlow 完全没有采集 worker，只能手动填回数据库 |

### 1.3 浏览器扩展的核心约束（来自 `browser-extension/README.md`）

> "The extension never clicks the final Publish button."
> "A v2 work order can be marked published only after the extension detects a public URL and the server receives a successful HTTP 200 readback receipt."

也就是说当前 GEOFlow 把"最后一步"押在"用户人工打开浏览器 → 平台真实账号登录 → 肉眼校验 → 点 Publish"。这正是用户"步骤繁琐"的根因。

---

## 二、改造目标

| 目标 | 度量 |
|---|---|
| 11 个 C 端平台全流程无人参与 | 工作单从 `ready` 到 `completed` 完全不需要浏览器扩展或人工 |
| 网页版 AI 平台纳入自动采集 | `AiVisibilityRun` 新增 5+ 个网页版 provider，无需任何手工 |
| 浏览器扩展退役或降级为"只读审阅台" | 后台面板可见但不再下发工作单 |
| 一键触发 → 全平台并行 | 用户在管理后台点一次按钮，覆盖所有平台 |

---

## 三、改造架构总览

```
┌────────────────────────────────────────────────────────────────────────┐
│  GEOFlow Laravel 容器（app/queue/web/scheduler）                       │
│                                                                        │
│  ┌──────────────────────────────────────────┐  ┌────────────────────┐ │
│  │ 已有：DistributionOrchestrator /          │  │ 已有：               │ │
│  │ ArticleDistributionJob                     │  │ AiVisibilityService │ │
│  │ （API 直发）                              │  │ （API 渠道）         │ │
│  └──────────────────────────────────────────┘  └────────────────────┘ │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐ │
│  │ 新增：BrowserPublicationService + BrowserPublicationPublisher  │ │
│  │ 实现 DistributionPublisherInterface，统一编排 API/网页直发   │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐ │
│  │ 新增：PlaywrightWebVisibilityProvider ×                       │ │
│  │ 实现 AiVisibilityProviderInterface                         │ │
│  └──────────────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────────┘
                                  │ gRPC/HTTP
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│  新增：geoflow-browser-worker 容器（独立 Python 服务）                  │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐ │
│  │ FastAPI 控制面                                                    │ │
│  │  - POST /tasks          接收 Laravel 下发的发布/采集任务           │ │
│  │  - POST /tasks/{id}/result  回写结果 + 媒体文件                    │ │
│  │  - POST /auth/login     首次授权（启动 headless 浏览器）           │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐ │
│  │ Playwright 引擎（async）                                           │ │
│  │  - CloakBrowser（patched stealth Chromium）                          │ │
│  │  - stealth.min.js + 反指纹 hooks                                     │ │
│  │  - 每个账号独立 storage_state（加密落盘）                            │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐ │
│  │ Step Executor（来自刚才反编译的"auth helper"思路）                │ │
│  │  - 步骤 JSON 模板（per-platform）                                   │ │
│  │  - 操作类型：navigate/click/fill/press/input_files/hover/branch  │ │
│  │  - 自定义操作：wxgzh-timmer / baidu-timmer / publish-confirm 等   │ │
│  └──────────────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 四、需要新增 / 修改的模块

### 4.1 新增：`geoflow-browser-worker` 独立服务（容器）

**新增文件**：`geoflow-browser-worker/`（与 `geoflow-updater` 平级，放仓库根）

```
geoflow-browser-worker/
├── Dockerfile                  # python:3.12-slim + playwright + cloakbrowser
├── pyproject.toml
├── app/
│   ├── main.py                 # FastAPI 入口
│   ├── config.py               # 读 Laravel 共享的 .env（GEOFLOW_BR_*）
│   ├── tasks.py                # Pydantic Task / Result 模型
│   ├── executor/
│   │   ├── browser.py          # Playwright + CloakBrowser 启动
│   │   ├── step.py             # Step JSON 解析与执行
│   │   └── selector.py         # CSS/XPath 工具
│   ├── steps/                  # 各平台 step_list JSON
│   │   ├── bjh.py              # 百家号（发布后
│   │   ├── sohu.py             # 搜狐号
│   │   ├── zhihu_column.py
│   │   ├── toutiao.py
│   │   ├── csdn.py
│   │   ├── jianshu.py
│   │   ├── netease.py
│   │   ├── qq_penguin.py
│   │   ├── dayu.py
│   │   ├── douyin.py
│   │   ├── weibo.py
│   │   └── ai/                 # 网页版 AI 平台采集 step
│   │       ├── kimi.py
│   │       ├── zhipu.py
│   │       ├── wenxin.py
│   │       ├── doubao_web.py
│   │       ├── deepseek_web.py
│   │       └── gemini.py
│   ├── auth/
│   │   ├── login_capture.py    # 监听 DOM 决定登录态
│   │   └── storage.py          # 账号 storage_state 持久化（Fernet 加密）
│   ├── callback.py             # POST 回 Laravel
│   └── result_normalizer.py    # 统一采集结果结构
└── README.md
```

**关键设计**：
1. **完全独立的容器**，不进 GEOFlow Laravel 容器，避免污染 PHP 运行时
2. **共享账号存储目录**：通过 `volumes:` 把宿主 `/var/lib/geoflow-browser-worker/storage_state/` 挂进 worker 容器，GEOFlow 容器也能读
3. **共享 Redis**：worker 通过 `REDIS_PASSWORD` 直接订阅 Laravel 的 `self-media` 队列上"浏览器 worker"专用 key（如 `geoflow:browser:tasks:queue`），避免双写
4. **共享 API Token**：Laravel 给 worker 颁发一个长期 API Token（`browser-operations:execute` scope），worker 用它回调 Laravel

### 4.2 新增：Laravel 侧 `BrowserPublicationPublisher`（PHP 新模块）

**新增文件**：
- `app/Services/BrowserOperations/BrowserPublicationPublisher.php` — **实现 `DistributionPublisherInterface`**，把"最终发布"动作通过 HTTP 委派给 `geoflow-browser-worker`
- `app/Services/GeoFlow/AiVisibility/Providers/PlaywrightWebVisibilityProvider.php` — **实现新的 `AiVisibilityProviderInterface`**，专门跑网页版 AI 平台
- `app/Services/BrowserOperations/BrowserWorkerClient.php` — Laravel 调 worker 的 SDK

**核心逻辑 `BrowserPublicationPublisher::publish()`**：
```
1. 锁定 ManualPublication（与 ManualPublicationBrowserService::claim 同等语义）
2. 把 article / portable_document / media_manifest 序列化成 BrowserTask
3. POST worker:/tasks，等待 task_id
4. 写 status=in_progress，写 browser_claimed_by_token_id=worker_token
5. 启动 Laravel 端轮询：每 30s 检查 worker 回写结果
6. 结果回来后：更新 status=completed/failed、回写 completion_url / execution_receipt
7. 与现有 draft_readback / public_url 自助核验复用（参考 BrowserManualPublicationController::receipt）
```

### 4.3 修改：`DistributionPublisherManager`

**修改文件**：`app/Services/GeoFlow/DistributionPublisherManager.php`

把 `BrowserPublicationPublisher` 注册为新的 channel_type：
```php
DistributionChannel::TYPE_BROWSER_WORKER => BrowserPublicationPublisher::class,
```

**修改 `DistributionChannel` 模型**：新增 `TYPE_BROWSER_WORKER = 'browser_worker'` 常量、配套 `isBrowserWorker()` 方法、字段（如 `worker_base_url`、`worker_token`）。

### 4.4 修改：`SelfMediaBatchService` / `SelfMediaPlatformRouter`

**修改文件**：
- `app/Services/SelfMedia/SelfMediaPlatformRouter.php` — 给 11 个 C 端平台映射到 worker 容器（每个平台对应一个 worker 步骤集）
- `app/Services/SelfMedia/SelfMediaBatchService.php` — `BROWSER_CONCURRENCY` 常量从 1 调到 5~10（worker 是真并发的，不受 BrowserExtension 的"同一 Chrome 一次一条"约束）

### 4.5 修改：`AiVisibilityCollectionService`

**修改文件**：`app/Services/GeoFlow/AiVisibility/AiVisibilityCollectionService.php`

在 `collect()` 中加入网页版 AI 平台分支：
```php
$webProvider = $this->configuration->webProvider($identity, $platform);  // kimi/zhipu/...
if ($webProvider instanceof AiSourceProvider) {
    return ['web_run' => $this->visibility->runWebBrowserQuery($identity, $webProvider, $keyword)];
}
```

**新增模型/迁移**：
- 迁移：`2026_09_11_000000_add_browser_worker_to_distribution_channels.php`
- 迁移：`2026_09_11_000000_add_web_visibility_provider_to_ai_source_providers.php`
- 模型：在 `AiSourceProvider` 增加 `PROVIDER_KIMI_WEB`、`PROVIDER_ZHIPU_WEB` 等常量
- 模型：在 `AiVisibilityRun` 增加 `PROVIDER_PLAYWRIGHT_WEB = 'playwright_web'` 常量

### 4.6 新增：配置项（`.env.prod` 增量）

```bash
# === 新增：浏览器 worker ===
GEOFLOW_BROWSER_WORKER_ENABLED=true
GEOFLOW_BROWSER_WORKER_URL=http://geoflow-browser-worker:8081
GEOFLOW_BROWSER_WORKER_TOKEN=__BROWSER_WORKER_RUNTIME_TOKEN__   # 由 install.sh 生成
GEOFLOW_BROWSER_WORKER_STORAGE_DIR=/var/lib/geoflow-browser-worker/storage_state
GEOFLOW_BROWSER_WORKER_HEADLESS=true
GEOFLOW_BROWSER_WORKER_DEFAULT_PROXY=                       # 可选全局代理
GEOFLOW_BROWSER_WORKER_MAX_CONCURRENT_PUBLICATIONS=8
GEOFLOW_BROWSER_WORKER_MAX_CONCURRENT_VISIBILITY=4
GEOFLOW_BROWSER_WORKER_BROWSER_FINGERPRINT_SEED=            # 可选，留空随机

# === CloakBrowser 许可（商业 stealth Chromium）===
GEOFLOW_CLOAKBROWSER_LICENSE_KEY=
GEOFLOW_CLOAKBROWSER_BINARY_PATH=/opt/cloakbrowser/chromium-146.0.7680.177.5/chrome

# === 网页版 AI 平台凭据（每平台账号需先在 GEOFlow 后台授权）===
# Kimi / 智谱 / 文心一言 / 豆包网页 / DeepSeek 网页 / Gemini 等
# 每个账号对应 storage_state 路径，登录授权一次后复用
```

### 4.7 新增：`docs/plans/2026-09-11-browser-worker-architecture.md`（本文档落档）

### 4.8 浏览器扩展的角色转换

**保留但降级**：`browser-extension/` 不删除，转为：

1. **只读审阅台**：管理员可以打开扩展**查看**工作单状态（不发任务）
2. **应急手动入口**：worker 全失败时仍允许人工顶上去
3. **登录态首次授权工具**：账号首次需要在扩展里登录一次，存储 cookies/storage_state → Laravel → worker

新增迁移：`2026_09_11_000000_add_browser_worker_token_to_personal_access_tokens.php`（区分"扩展 token"和"worker token"）。

新增 `BrowserClientType::WORKER` 常量 + UI 标注。

### 4.9 部署：`docker-compose.prod.yml` 新增服务

```yaml
  browser-worker:
    image: ${GEOFLOW_BROWSER_WORKER_IMAGE:-${COMPOSE_PROJECT_NAME:-geoflow}-browser-worker:latest}
    build:
      context: ./geoflow-browser-worker
      dockerfile: Dockerfile
    environment:
      GEOFLOW_LARAVEL_URL: http://app:9000
      GEOFLOW_BROWSER_WORKER_TOKEN: ${GEOFLOW_BROWSER_WORKER_TOKEN}
      GEOFLOW_CLOAKBROWSER_LICENSE_KEY: ${GEOFLOW_CLOAKBROWSER_LICENSE_KEY}
      PLAYWRIGHT_BROWSERS_PATH: /opt/playwright
    volumes:
      - ./.env:/app/.env:ro
      - /var/lib/geoflow-browser-worker:/var/lib/geoflow-browser-worker
      - /tmp/geoflow-browser-worker:/tmp
    cap_add: [SYS_ADMIN]                # CloakBrowser 需要
    security_opt: [seccomp=unconfined]  # CloakBrowser 推荐
    mem_limit: 2g
    cpus: 2
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8081/healthz"]
      interval: 30s
      timeout: 10s
      retries: 3
    depends_on:
      app:
        condition: service_healthy
```

新增 `geoflow-updater enroll` 子命令：`--with-browser-worker`，同步注册 worker。

### 4.10 新增：账号授权工作流（保留"一次扫码、长期复用"）

参考现有 `browser-extension/` 的 `tikok_login` 模式：
- **首次**：管理员在 GEOFlow 后台点"绑定账号" → 后台弹窗打开目标平台登录页（用 worker 容器的 headless 浏览器） → 用户扫码/输入 → 检测 DOM 选择器（百家号 `.UjPPKm89R4RrZTKhwG5H`、抖音 `.img-PeynF_`、公众号 `.weui-desktop_name` 等）→ 抓 cookies + localStorage → 加密存盘
- **复用**：worker 启动 Playwright 时直接 `storage_state=...` 加载，无需重新登录

**复用刚才反编译 `auth helper` 的真实选择器清单**（已确认 `src/api/script/*.js` 里的 DOM 选择器），不需要从零摸索。

---

## 五、关键复用资产（来自"auth helper"反编译）

`auth helper`（`D:/GEO助手/auth helper/`）已经把这套基础设施**完整实现**了，反编译后的关键资产可以直接复用思路：

| 反编译得到的资产 | 复用方式 |
|---|---|
| `src/script/wxgzh.py` / `baidu.py` / `toutiao.py` 等 13 个平台 step_list JSON 模板 | 直接移植到 `geoflow-browser-worker/app/steps/` |
| `src/tikok_login/login.py` 等 4 个 Playwright 授权脚本 | 思路移植 |
| `src/common.py:get_stealth()` 加载 `stealth.min.js` | 移植 |
| `src/common.py:load_storage_state()` 生成 `%APPDATA%/geo/tempfile/storage_state_{ms}.json` | 移植，改用 Linux 路径 `/var/lib/geoflow-browser-worker/storage_state/` |
| `cloakbrowser` 模块 + `CHROMIUM_VERSION = '146.0.7680.177.5'` | 整包移植，加 license 校验 |
| 反检测栈（CloakBrowser + stealth.min.js + 代理 + UA） | 直接复用 |
| `multi_thread_run` 多线程拉 SaaS 任务 + 多 worker 并发 | 架构复用，但任务源从 SaaS 改为 Laravel Redis |
| `process_task` 的 `msg_version` 增量日志轮询协议 | 同协议回写到 Laravel |

**Worker 内的 `Step Executor` 字段**（从 wxgzh.py 反编译得出，已与 GEOFlow 的 `ManualPublication.portable_document` 对齐）：

| 字段 | 类型 | 用途 |
|---|---|---|
| `type` | `string` | navigate / click / fill / press / wait_for_selector / wait_for_url / hover / input_files / branch / new_page_info / wxgzh-timmer / baidu-timmer |
| `selector` | `string` | CSS 或 XPath |
| `value` | `string` | 引用 `ManualPublication.portable_document.*` 字段（title / content / author / files / is_yuanchuang / timing / ai_status ...） |
| `descript` | `string` | 中文描述，写日志用 |
| `timeout` / `is_wait` / `is_try` / `is_exist` | `int / int / int / int` | 时间与容错 |
| `nth` | `int` | 第几个匹配 |
| `force` | `bool` | 是否绕过 actionability |
| `child_selector` | `list[Step]` | 子步骤（弹窗/新页面） |
| `is_list` / `else_list` | `list[Step]` | 分支判断 |
| `is_value` | `string` | 引用 ManualPublication 字段判断分支条件 |

---

## 六、落地分期（建议 4 个里程碑）

### M1 — worker 容器 + 一个端到端平台（1~2 周）

- 新增 `geoflow-browser-worker/` 骨架（FastAPI + Playwright + CloakBrowser）
- 实现 `BrowserWorkerClient` + `BrowserPublicationPublisher`
- 选**头条**作为首个端到端平台（DOM 稳定、反爬最弱） → 跑通 ready → completed 全链路
- `DistributionOrchestrator` 增加 `TYPE_BROWSER_WORKER`
- `geoflow-browser-worker` 加进 `docker-compose.prod.yml`
- 同步 `geoflow-updater enroll` 支持 `--with-browser-worker`

**验收**：在 GEOFlow 后台为一篇文章勾"头条" + 点发布，不再需要开浏览器，后台看到 completed + 公网 URL。

### M2 — 11 个 C 端平台全部接入（2~3 周）

- 移植 `auth helper` 的 13 个 step_list 到 `geoflow-browser-worker/app/steps/`
- 每个平台单独的 `dispatch` 队列（避免一个平台失败阻塞其他）
- 接入 GEOFlow 现有 `SelfMediaPlatformRouter` 的 11 平台
- 复用 `ManualPublicationBrowserService` 的所有状态机（`STATUS_IN_PROGRESS`/`STATUS_DRAFT_FILLED`/`STATUS_COMPLETED`/`STATUS_FAILED`）
- 浏览器扩展降级为只读

**验收**：同一篇文章勾 11 平台一次性发布，全部 completed 或明确 failed（不再有 outcome_unknown）。

### M3 — 网页版 AI 平台自动采集（2 周）

- 新增 `PlaywrightWebVisibilityProvider` × 6（豆包网页/DeepSeek 网页/Kimi/智谱/文心/Gemini）
- `AiVisibilityCollectionService::collect()` 加入网页版分支
- 复用 worker 容器，每个账号独立 storage_state
- 复用 worker 的 result 回写到 `AiVisibilityRun` + `AiVisibilitySource`
- 启动 `geoflow:schedule-ai-visibility-web` 每 30 分钟扫描缺数据的关键词

**验收**：在 GEOFlow 后台给一个关键词触发采集 → 同时跑豆包 API + 豆包网页 + Kimi 网页 → 三个 run 都回写到 `ai_visibility_runs` 表。

### M4 — 一键触发 + 全平台 + 永久人工门槛清除（1 周）

- 后台"发布助手"页面新增"一键全平台"按钮
- 触发链路：文章 → `ManualPublicationBatch` (intent=auto) → `SelfMediaBatchService::createAutomaticIfEnabled` → 全平台分发 + 全部 worker 化
- 浏览器扩展 README 加 migration note（保留供应急）
- `docs/browser-operations-runbook.md` 大幅更新到 worker 模型
- `docs/self-media-publication-assistant.md` 同步

**验收**：用户**完全不开浏览器**，从一篇文章到 11 平台全部发布完成（最慢 ~10 分钟）。

---

## 七、风险与对策

| 风险 | 影响 | 对策 |
|---|---|---|
| 平台 DOM 改版导致 step_list 失效 | 全平台发不出 | 保留浏览器扩展作应急；step_list 上报机制（同 auth helper 的 sava_step_list 思路） |
| CloakBrowser 商业许可到期 | 反检测降级 | 加 license 监控，licence  临近过期前告警；可选降级到 puppeteer-extra-plugin-stealth（免费） |
| worker 单点故障 | 全平台停滞 | docker-compose 加 replicas=2 + healthcheck；web/queue 不直接调 worker，走 Redis 队列 |
| 账号被平台风控 | 单账号停摆 | 每账号独立代理 IP（`agent_ip_url`）；UA 轮换；多账号负载均衡 |
| Laravel 容器没有 Python 运行时 | 部署失败 | 严格隔离：worker 独立容器，PHP 不污染 |
| Storage state 跨容器共享 | 文件锁/并发问题 | worker 单写、Laravel 只读；目录权限 0640 root:geoflow-browser-worker |

---

## 八、改动清单（精确到文件路径）

### 新增（约 18 个文件）

```
geoflow-browser-worker/                                   # 仓库根新目录
├── Dockerfile
├── pyproject.toml
├── README.md
├── app/main.py
├── app/config.py
├── app/tasks.py
├── app/executor/browser.py
├── app/executor/step.py
├── app/executor/selector.py
├── app/steps/{bjh,sohu,zhihu_column,toutiao,csdn,jianshu,netease,qq_penguin,dayu,douyin,weibo}.py
├── app/steps/ai/{kimi,zhipu,wenxin,doubao_web,deepseek_web,gemini}.py
├── app/auth/{login_capture,storage}.py
├── app/callback.py
└── app/result_normalizer.py

GEOFlow-main/
├── app/Services/BrowserOperations/BrowserPublicationPublisher.php
├── app/Services/BrowserOperations/BrowserWorkerClient.php
├── app/Services/GeoFlow/AiVisibility/Providers/PlaywrightWebVisibilityProvider.php
├── app/Services/GeoFlow/AiVisibility/AiVisibilityProviderInterface.php   # 新增接口
├── app/Console/Commands/GeoflowBrowserWorkerHealthCommand.php
├── database/migrations/2026_09_11_000000_add_browser_worker_to_distribution_channels.php
├── database/migrations/2026_09_11_000000_add_web_visibility_provider_to_ai_source_providers.php
├── database/migrations/2026_09_11_000000_add_browser_worker_token_to_personal_access_tokens.php
├── docs/plans/2026-09-11-browser-worker-architecture.md                   # 本文档
└── docs/browser-operations-runbook.md                                    # 大改
```

### 修改（约 9 个文件）

```
GEOFlow-main/
├── docker-compose.prod.yml                                              # + browser-worker service
├── geoflow-updater  (二进制内含 enroll --with-browser-worker)
├── app/Services/GeoFlow/DistributionPublisherManager.php                 # 注册新 publisher
├── app/Models/DistributionChannel.php                                   # + TYPE_BROWSER_WORKER 常量
├── app/Models/AiSourceProvider.php                                      # + PROVIDER_*_WEB 常量
├── app/Models/AiVisibilityRun.php                                       # + PROVIDER_PLAYWRIGHT_WEB 常量
├── app/Services/SelfMedia/SelfMediaBatchService.php                     # BROWSER_CONCURRENCY 1→8
├── app/Services/SelfMedia/SelfMediaPlatformRouter.php                   # 11 平台映射到 worker
└── app/Services/GeoFlow/AiVisibility/AiVisibilityCollectionService.php  # + 网页版分支
```

### 不动但降级

```
GEOFlow-main/browser-extension/   # 保留代码，README 加"仅供应急"提示
```

---

## 九、与"auth helper"（GEO 助手桌面工具）的边界

`auth helper` 是用户本机已有的桌面端 SaaS 客户端（Electron + Playwright + 远端 SaaS `http://8.138.58.181`），它做的是**多账号多平台**的"营销内容分发"。

`GEOFLOW` 是用户自建的服务端 Laravel 应用，它做的是**自家品牌 AI 可见度诊断 + 自媒体内容发布到自家账号**。

两者**底层能力高度重叠**（都是 Playwright + step_list + CloakBrowser 反检测），但**任务源不同**：
- auth helper 的任务源 = 远端 SaaS
- GEOFlow 的任务源 = Laravel 数据库里的 `ManualPublication`

**改造后两者完全独立，不互相调用**。但 `geoflow-browser-worker` 可以**借鉴** auth helper 的 step_list 模板（百家号、公众号、头条等 11 平台选择器已写好现成的）。

---

## 十、需要你拍板的 3 件事

1. **CloakBrowser 商业许可**：你这边是否已有 `cloakbrowser.dev` 商业 license？没有的话可以降级到免费的 `puppeteer-extra-plugin-stealth`（需要重写部分指纹对抗逻辑，强度弱一些但够用）。
2. **是否保留浏览器扩展**：建议保留为"应急人工入口" + "首次账号授权工具"，否则需要另外写授权流程。如果你希望一刀切全部下线，可以省一些工作量但风险更高。
3. **改造节奏**：M1 头条端到端先跑通，M2 扩到 11 平台，M3 上 AI 网页版采集，M4 一键全平台。是否按这个顺序？或者先做 M3（AI 网页采集）的收益更直接（用户痛点更明显）？

明确后我可以直接进入 M1 实施。