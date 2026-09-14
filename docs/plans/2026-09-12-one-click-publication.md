# GEOFlow 一键全平台自动发布实施方案

> 日期：2026-09-12
> 范围：`prod/v3.1.0-selfmedia` 分支（`GEOFlow-main/`）
> 前置文档：`docs/plans/2026-09-11-browser-worker-architecture.md`（总架构）
> 本文目标：把"六个具体需求"翻译成**可执行的步骤清单 + 配置 + 验证**，"按步骤执行即可"

---

## 0. 6 个需求 ↔ 文档结构对照

| 需求编号 | 需求要点 | 对应章节 |
|---|---|---|
| ① | 双模式（AI 自动 / 手动同步） | § 3 双模式架构、§ 6.4 任务模式切换 |
| ② | 图片自动上传 | § 4 图片上传子系统 |
| ③ | CloakBrowser 商业 license | § 5 反检测栈（含替代方案） |
| ④ | 多账号管理（几十上百个） | § 7 账号管理体系 |
| ⑤ | 完整配置流程 | § 8 端到端部署与配置 |
| ⑥ | 扩展性 | § 9 扩展性设计 + step_list 插件化 |
| — | 验证 | § 10 发布验证与监控 |

---

## 1. 一句话总览

```
┌─────────────────┐  点"一键发布"   ┌──────────────────────────────┐
│ GEOFlow 管理后台 │ ──────────────► │ Laravel (PHP) 队列调度         │
└─────────────────┘                 └──────────────┬────────────────┘
                                                  │ BrowserTask
                                                  ▼
                              ┌──────────────────────────────────┐
                              │ geoflow-browser-worker (Python)  │
                              │  ┌──────────┐  ┌───────────────┐  │
                              │  │ Playwright│─►│CloakBrowser Pro│ │
                              │  └──────────┘  └───────────────┘  │
                              │         │                         │
                              │         ▼                         │
                              │  ┌──────────────────────────┐     │
                              │  │ Step Executor            │     │
                              │  │  navigate/click/fill/... │     │
                              │  └──────────────────────────┘     │
                              │         │                         │
                              │         ▼                         │
                              │  ┌──────────────────────────┐     │
                              │  │ 真实平台账号（storage_state）│    │
                              │  └──────────────────────────┘     │
                              └──────────────┬───────────────────┘
                                             │ 回调
                                             ▼
                              ┌──────────────────────────────────┐
                              │ ManualPublication.completed       │
                              │ + completion_url + receipt        │
                              └──────────────────────────────────┘
```

**核心承诺**：管理员在后台点一次按钮 → 11 个平台全部真实账号登录 → 自动填充/上传图片 → 自动点 Publish → 结果回写到数据库 → 全程**无需打开本地浏览器**。

---

## 2. 技术栈选型

| 层 | 选型 | 理由 |
|---|---|---|
| 队列 | Laravel Queue（Redis） | 复用 `geoflow`/`self-media` 队列基建 |
| Worker 运行时 | Python 3.12 + FastAPI + Playwright | 与 `auth helper` 反编译出的栈一致，资产可移植 |
| 反检测浏览器 | **CloakBrowser Pro**（首选）/ puppeteer-extra-plugin-stealth（备选） | 见 § 5 |
| 账号状态持久化 | `storage_state` JSON + Fernet 加密落盘 | 复用 auth helper 的 storage 机制 |
| 图片处理 | Pillow（压缩/转码）+ requests（下载）+ OSS SDK（上传） | 平台要求多在 5MB/1080P 内 |
| 反指纹 | UA + 视口 + WebGL + 语言 + 时区随机化 + 代理 IP | 见 § 5.3 |
| 配置中心 | Laravel `.env`（PHP 侧）+ worker `.env`（Python 侧，共享） | 单一来源 |

---

## 3. 双模式架构（需求①）

### 3.1 两种模式定义

| 模式 | 触发方式 | 谁点 Publish | 适用场景 |
|---|---|---|---|
| **`mode = auto`**（默认） | 后台点"一键发布" | **worker 自己点** | 信任度高的账号、批量场景、凌晨跑 |
| **`mode = semi_auto`** | 后台点"同步到平台待我确认" | **worker 填好但不下发**，扩展接管 | 风险稿、要人复核、要重新改稿 |

> **同一篇文章同一时间只能选一种模式**（由 `ManualPublication.mode` 决定），但账号级别可配置默认偏好。

### 3.2 数据模型

**修改模型 `ManualPublication`**（`app/Models/ManualPublication.php`）：

```php
// 新增字段（需迁移 2026_09_12_000000_add_publication_mode_to_manual_publications.php）
public const MODE_AUTO     = 'auto';     // worker 全自动
public const MODE_SEMI     = 'semi_auto';// worker 填好 → 等扩展/人工
public const MODE_MANUAL   = 'manual';   // 完全人工（兼容旧路径）

protected $fillable = [
    // ... 既有字段
    'mode',                            // auto|semi_auto|manual
    'pending_publish_at',              // semi 模式下填好后的等待时间
    'semi_auto_completion_url',        // worker 填好后的预览 URL（semi 专用）
];
```

**新增模型 `ManualPublicationMode`（配置）**：

```php
// app/Models/ManualPublicationModePreference.php
class ManualPublicationModePreference extends Model {
    protected $fillable = ['account_id', 'platform', 'default_mode', 'require_reviewer_id'];
}
```

### 3.3 状态机（双模式兼容）

```
                    ┌──────┐
                    │ new  │
                    └──┬───┘
                       │ enqueue
                       ▼
            ┌─────────────────────┐
            │ ready_for_browser   │  ← worker 已收到任务
            └──────────┬──────────┘
                       │
        ┌──────────────┴──────────────┐
        │ mode=auto                   │ mode=semi_auto
        ▼                             ▼
   ┌──────────┐                  ┌────────────────┐
   │ publishing│ worker 真正点     │ draft_filled    │ worker 填好不点 Publish
   └────┬─────┘                  └────────┬───────┘
        │                                │ 等扩展/人工 → 触发 callback
        ▼                                ▼
   ┌──────────┐                  ┌────────────────┐
   │ completed│                  │ completed       │ 人工最终确认完成
   └──────────┘                  └────────────────┘
```

**修改文件**：
- `app/Services/BrowserOperations/ManualPublicationBrowserService.php` 增加 `transitionTo()` 状态机
- 新增 `app/Services/BrowserOperations/ModeAwarePublisher.php` — 模式感知发布器
- `app/Models/ManualPublication.php` 增加 5 个新常量 + 状态机守卫

### 3.4 用户侧切换体验

**位置**：`/admin/manual-publications/{id}/edit`

- 文章右侧新增"**发布模式**"卡片，下拉选 auto/semi_auto/manual
- 顶部多选发布时，勾选框旁显示当前账号的默认模式
- 列表页新增"模式"列，可批量筛选
- 一键发布按钮文案智能切换："一键自动发布 (auto)" vs "同步到平台 (semi)"

---

## 4. 图片自动上传子系统（需求②）

### 4.1 当前痛点

手动操作时：写文章 → 切到平台 → 拖图片到平台上传框 → 等上传完成 → 设封面 → 提交。
**耗时**：单平台 1~3 分钟，11 平台累加 15~30 分钟。

### 4.2 自动化方案

```
┌─────────────────┐
│ 文章含 n 张图    │ (portable_document.images[])
└────────┬────────┘
         │
         ▼
┌──────────────────────────────────────┐
│ ImagePreprocessor（worker 内）        │
│  - 下载远程图 → 本地 /tmp/img/{ts}/  │
│  - 压缩到 ≤ 1080×1440、JPEG 85%      │
│  - 体积 ≤ 5MB（平台约束）              │
│  - 生成封面候选（首图/指定图）         │
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│ PerPlatformImageAdapter              │
│  - bjh:      image/* input（多文件） │
│  - wxgzh:    单封面图（首图）         │
│  - xhs:      9 宫格（特殊裁切）      │
│  - toutiao:  3 图轮播 + 封面         │
│  - zhihu:    单封面图                 │
│  - ...其他平台见 §4.3                 │
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│ PlatformUploadExecutor               │
│  - 在 step_list 里 input_files 步骤   │
│  - 用 Playwright locator.set_input_files │
│  - 等待上传完成 DOM 信号              │
│  - 自动裁切（小红书 9 宫格）           │
└──────────────────────────────────────┘
```

### 4.3 各平台图片适配（首版）

| 平台 | input 类型 | 数量上限 | 自动处理 |
|---|---|---|---|
| 百家号 | `<input type=file multiple>` | 60 | 直接灌入 |
| 头条 | `<input type=file multiple>` | 9 | 直接灌入 |
| 公众号 | 单封面（首图） | 1 | 单图压缩 |
| 小红书 | `<input type=file multiple>` | 9/18 | **9 宫格自动切**（必须 1:1 或 3:4） |
| 知乎 | 单封面 | 1 | 单图压缩 |
| 微博 | `<input type=file>` | 9 | 直接灌入 |
| 抖音 | 视频封面自动截图 | 1 | `ffmpeg` 第一帧 |
| 搜狐号 | `<input type=file multiple>` | 30 | 直接灌入 |
| 简书 | `<input type=file multiple>` | 20 | 直接灌入 |
| CSDN | `<input type=file multiple>` | 30 | 直接灌入 |
| 网易号 | `<input type=file>` | 12 | 直接灌入 |

### 4.4 step_list 中的图片步骤（以百家号为例）

```python
# geoflow-browser-worker/app/steps/bjh.py
def step_list():
    return [
        {'type': 'navigate', 'selector': 'https://baijiahao.baidu.com/builder/editor/draft',
         'descript': '打开百家号编辑器', 'is_wait': 10},
        # ...填标题、正文...
        {'type': 'input_files', 'selector': 'input[type=file]',
         'value': 'files',  # 引用 data_item.files = [本地绝对路径列表]
         'descript': '上传正文配图（≤ 60 张）', 'timeout': 60},
        {'type': 'wait_for_selector',
         'selector': '.img-loaded',  # 平台上传完成信号
         'descript': '等待图片上传完成', 'is_wait': 30},
        {'type': 'click', 'selector': '.cover-set-btn',
         'descript': '打开封面选择'},
        {'type': 'click', 'selector': '.cover-item:first-child',
         'descript': '选择首图为封面'},
        # ...原创分支...
        {'type': 'click', 'selector': '.publish-btn',  # ← auto 模式才执行
         'is_condition': "mode == 'auto'",  # 新增字段
         'descript': '点击发布'},
    ]
```

**新增 step 类型**：`is_condition`（动态判断是否执行），让同一份 step_list 兼容 auto/semi。

### 4.5 小红书 9 宫格自动切

```python
# geoflow-browser-worker/app/steps/xhs.py 辅助
def split_into_3x3_grid(image_path: str) -> list[str]:
    """把 1 张图切成 3×3 共 9 张 1:1 子图，供小红书 9 宫格发布。"""
    from PIL import Image
    img = Image.open(image_path)
    w, h = img.size
    side = min(w, h) // 3
    # 取中心正方形
    cx, cy = w // 2, h // 2
    half = side * 3 // 2
    square = img.crop((cx - half, cy - half, cx + half, cy + half))
    outputs = []
    for row in range(3):
        for col in range(3):
            sub = square.crop((col * side, row * side, (col + 1) * side, (row + 1) * side))
            out_path = f'/tmp/xhs_grid/{int(time.time()*1000)}_r{row}c{col}.jpg'
            sub.save(out_path, 'JPEG', quality=85)
            outputs.append(out_path)
    return outputs
```

### 4.6 新增文件清单

```
geoflow-browser-worker/app/media/
├── __init__.py
├── preprocessor.py        # 下载/压缩
├── adapters.py            # 11 平台适配规则
├── grid.py                # 小红书 9 宫格切图
├── video_cover.py         # ffmpeg 截封面
└── uploader.py            # 实际 Playwright set_input_files
```

---

## 5. 反检测栈与 CloakBrowser 详解（需求③）

### 5.1 平台风控三层模型

```
┌────────────────────────────────────────┐
│ 第 1 层：协议指纹                          │
│  - TLS 指纹（JA3/JA4）                     │
│  - HTTP/2 指纹（Akamai fingerprint）                  │
│  - 浏览器二进制本身（被读 /proc/.../maps）         │
├────────────────────────────────────────┤
│ 第 2 层：浏览器内部指纹                       │
│  - navigator.webdriver 标记                │
│  - navigator.plugins / languages           │
│  - Canvas / WebGL 渲染指纹                 │
│  - 音频上下文指纹                          │
│  - 屏幕尺寸 / 视口 / DPR                    │
├────────────────────────────────────────┤
│ 第 3 层：行为指纹                            │
│  - 鼠标轨迹（真人有抖动）                    │
│  - 键盘节奏                               │
│  - 滚动速度                                │
│  - 停留时间                                │
└────────────────────────────────────────┘
```

**普通 Playwright** 只做了第 2 层的一半（移除 `navigator.webdriver`），第 1、3 层完全不处理。
**CloakBrowser** 是少有的**商业产品**同时覆盖三层。

### 5.2 CloakBrowser Pro 是什么

**CloakBrowser**（`cloakbrowser.dev`）是 **Invisibility Labs** 出品的**二进制补丁版 Chrome/Chromium**：

- **官网**：`https://cloakbrowser.dev`
- **原理**：直接 patch Chromium 源码（不是 JS 注入），所以**修改发生在 C++ 层**，常规 JS 探测脚本（包括 `bot-detector`/`FingerprintJS`）**根本读不到**原始值
- **覆盖能力**：
  - **协议层**：patched TLS 握手、伪造 Akamai/HTTP2 指纹
  - **二进制层**：移除 `--enable-automation`、隐藏 `chrome_crashpad`、伪造进程命令行
  - **JS 探测层**：自定义 `navigator.webdriver`、`navigator.plugins`、`window.chrome.runtime`、Canvas/WebGL 噪音
  - **行为层**：可选 mouse/keyboard humanizer
- **Python SDK**：
  ```python
  from cloakbrowser import CloakBrowser
  cb = CloakBrowser(license_key="...")
  page = cb.new_page(fingerprint={"os": "windows", "screen": (1920, 1080)})
  ```
- **License 形态**：
  - 个人版（`Indie`）：~$99/年，1 台机器
  - 团队版（`Team`）：~$499/年，5 台机器
  - 企业版（`Enterprise`）：定制价，无限机器 + 源码访问
  - license 通过 HTTPS API 校验：`https://cloakbrowser.dev/api/license/validate`

### 5.3 为什么推荐 CloakBrowser（不是别的）

| 方案 | 覆盖层 | 价格 | 易用性 | 反检测强度 |
|---|---|---|---|---|
| **CloakBrowser Pro** | 1+2+3 | $99~/年 | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ |
| `puppeteer-extra-plugin-stealth`（开源） | 2 | 免费 | ⭐⭐⭐⭐ | ⭐⭐⭐（JS 探测能绕过） |
| `nodriver`（开源，CDP 反检测） | 2 | 免费 | ⭐⭐⭐ | ⭐⭐⭐ |
| `undetected-chromedriver`（Selenium 用） | 2 | 免费 | ⭐⭐ | ⭐⭐⭐ |
| 自己 patch Chromium | 1+2+3 | 0（工程师成本 5~10 万） | ⭐ | ⭐⭐⭐⭐⭐ |

**结论**：
- **有 license 预算**：直接上 CloakBrowser Pro（**$99 一次性年费 + 5 行代码**），省 90% 工程量。
- **没有 license**：用 `puppeteer-extra-plugin-stealth` + 严格代理 IP + 真人鼠标轨迹模拟，也能用但**强度弱 1 个档次**，百家号/小红书可能触发二次验证。

### 5.4 替代方案配置（不花钱的路径）

如果暂时没 license，`geoflow-browser-worker` 支持**降级模式**：

```bash
# .env（worker 侧）
GEOFLOW_CLOAKBROWSER_LICENSE_KEY=                # 留空 = 降级到 stealth 模式
GEOFLOW_BROWSER_FALLBACK_ENABLED=true            # 降级开关
GEOFLOW_BROWSER_FALLBACK_STEALTH_PLUGIN_PATH=/opt/playwright-stealth/stealth.min.js
GEOFLOW_BROWSER_FALLBACK_HUMANIZE=true           # 启用鼠标轨迹模拟
GEOFLOW_BROWSER_FALLBACK_PROXY_REQUIRED=true     # 降级模式强制要求代理 IP
```

降级模式额外要求：
1. **每账号必须有独立代理 IP**（否则 24h 内必封）
2. **必须开启 humanize**：mouse 贝塞尔曲线、键盘节拍、随机停留 2~8s
3. **首次访问每个平台必须真人**通过二次验证

### 5.5 license 监控与告警

**新增模型 `LicenseMonitor`**：

```php
// app/Models/LicenseMonitor.php
class LicenseMonitor extends Model {
    // 每日 worker 启动时回写 license 状态
}
```

**新增命令**：`php artisan geoflow:browser-license-check`
- 提前 30 天发邮件告警
- 过期前 7 天发企微/飞书告警
- 过期后自动降级到 stealth 模式

### 5.6 工程实现核心代码（worker 侧）

```python
# geoflow-browser-worker/app/executor/browser.py
import os
from playwright.async_api import async_playwright

class BrowserLauncher:
    def __init__(self, config):
        self.config = config

    async def launch(self, account):
        if self.config.cloakbrowser_license_key:
            # === CloakBrowser 路径 ===
            from cloakbrowser import CloakBrowser
            cb = CloakBrowser(license_key=self.config.cloakbrowser_license_key)
            browser = await cb.launch(
                headless=self.config.headless,
                proxy=self._resolve_proxy(account),
                fingerprint=self._fingerprint_for(account),
            )
        else:
            # === 降级 stealth 路径 ===
            playwright = await async_playwright().start()
            browser = await playwright.chromium.launch(
                headless=self.config.headless,
                args=[
                    '--disable-blink-features=AutomationControlled',
                    '--no-sandbox',
                    '--disable-dev-shm-usage',
                    # 移除 webdriver 标识
                ],
            )
            context = await browser.new_context(
                storage_state=account.storage_state_path,
                proxy=self._resolve_proxy(account),
                user_agent=account.user_agent,
                viewport=account.viewport,
                locale='zh-CN',
                timezone_id='Asia/Shanghai',
                )
            await context.add_init_script(
                open('/opt/playwright-stealth/stealth.min.js').read()
            )
        return browser

    def _fingerprint_for(self, account):
        return {
            'os': 'windows',
            'screen': {'width': 1920, 'height': 1080},
            'webgl_vendor': 'Google Inc. (NVIDIA)',
            'webgl_renderer': 'ANGLE (NVIDIA, NVIDIA GeForce RTX 4060 Direct3D11 vs_5_0 ps_5_0)',
            'languages': ['zh-CN', 'zh', 'en-US', 'en'],
            'timezone': 'Asia/Shanghai',
            'seed': account.fingerprint_seed or random.randint(1, 10**9),
        }
```

---

## 6. 多账号管理体系（需求④）

### 6.1 数据模型（已存在 + 扩展）

GEOFlow 已有 `ManualPublicationAccount`（已盘点过）。扩展如下：

**修改模型 `ManualPublicationAccount`**：

```php
// app/Models/ManualPublicationAccount.php  新增字段
protected $fillable = [
    // ... 既有字段
    'platform',                // bjh|wxgzh|xhs|zh|wb|dy|...（已存在）
    'account_handle',          // @昵称（展示用）
    'avatar_url',              // 头像 URL（已存在）
    'follower_count',          // 粉丝数（已存在）
    'storage_state_path',      // /var/lib/geoflow-browser-worker/storage_state/{account_id}.json
    'storage_state_encrypted', // Fernet 加密后的 binary（兜底用）
    'proxy_url',               // http://user:pass@ip:port（每账号独立）
    'proxy_expires_at',
    'user_agent',              // UA（每账号固定一个，不要随机）
    'viewport',                // {"width":1920,"height":1080}
    'fingerprint_seed',        // 指纹种子（相同账号每次稳定）
    'default_mode',            // auto|semi_auto|manual
    'timezone',                // Asia/Shanghai
    'language',                // zh-CN
    'geo_location',            // {"lat":34.76,"lng":113.62}（小红书等 LBS 平台用）
    'health_score',            // 0~100（自动评估：发布成功率×权重）
    'last_published_at',
    'last_failed_at',
    'failure_streak',          // 连续失败计数（用于熔断）
    'cooldown_until',          // 熔断恢复时间
    'daily_quota_used',        // 今日已发布数（部分平台有日上限）
    'tags',                    // JSON: ["高价值", "主力", "备用"]
    'group_id',                // 账号分组（按律所、按业务线分）
];

// 关系
public function group() { return $this->belongsTo(AccountGroup::class); }
public function publications() { return $this->hasMany(ManualPublication::class); }
public function logs() { return $this->hasMany(AccountHealthLog::class); }
```

**新增模型 `AccountGroup`**：

```php
class AccountGroup extends Model {
    protected $fillable = ['name', 'description', 'default_mode', 'concurrency_limit'];
    // 例：博迈律所-主力-百家号 / 恒佳-备用-知乎
}
```

**新增模型 `AccountHealthLog`**：

```php
class AccountHealthLog extends Model {
    protected $fillable = [
        'account_id', 'event_type',  // login_success|publish_success|publish_failed|captcha|ban_warning
        'platform', 'publication_id', 'details_json', 'occurred_at'
    ];
}
```

### 6.2 新增迁移文件

```
2026_09_12_000000_create_account_groups_table.php
2026_09_12_000001_add_browser_fields_to_manual_publication_accounts.php
2026_09_12_000002_create_account_health_logs_table.php
2026_09_12_000003_add_publication_mode_to_manual_publications.php
2026_09_12_000004_create_manual_publication_mode_preferences.php
```

### 6.3 账号生命周期

```
① 创建账号 → ② 首次授权（扫码/输入）→ ③ 验证 storage_state → ④ 上线
                                                            ↓
                              ⑦ 熔断（连续失败 3 次）← ⑥ 日常发布 ← ⑤ 健康监控
                                                            ↓
                                              ⑨ 重新授权 ← ⑧ 平台风控（验证码/封禁）
```

**新增服务 `AccountLifecycleService`**（`app/Services/BrowserOperations/AccountLifecycleService.php`）：

```php
class AccountLifecycleService {
    public function create(array $data): ManualPublicationAccount { ... }
    public function authorize(ManualPublicationAccount $account): string  // 返回授权 URL { ... }  // 轮询直到成功
    public function verifyStorageState(string $path): bool
    public function recordEvent(ManualPublicationAccount $account, string $event, array $details): void
    public function shouldCooldown(ManualPublicationAccount $account): bool
    public function cooldown(ManualPublicationAccount $account, int $minutes): void
    public function reauthorize(ManualPublicationAccount $account): string
}
```

### 6.4 熔断策略

```php
// app/Services/BrowserOperations/AccountCircuitBreaker.php
class AccountCircuitBreaker {
    const FAILURE_STREAK_THRESHOLD = 3;   // 连续失败 3 次熔断
    const COOLDOWN_MINUTES = [           // 阶梯式冷却
        1 => 30,    // 第一次失败 → 冷却 30 分钟
        2 => 120,   // 第二次失败 → 冷却 2 小时
        3 => 1440,  // 第三次失败 → 冷却 24 小时
        'default' => 30,
    ];
    const HEALTH_SCORE_DROP = 15;        // 每次失败 health_score -15
}
```

### 6.5 批量发布路由

```php
// app/Services/BrowserOperations/AccountBatchRouter.php
class AccountBatchRouter {
    /**
     * 一篇文章 + N 个账号 → 拆分 N 个子任务，每个账号独立 worker
     */
    public function route(Article $article, array $accountIds, string $mode): Collection {
        return collect($accountIds)->map(function ($accountId) use ($article, $mode) {
            return ManualPublication::create([
                'article_id' => $article->id,
                'account_id' => $accountId,
                'mode' => $mode,
                'status' => 'pending',
            ]);
        });
    }

    /**
     * 一个账号 + 多篇文章 → 串行执行（避免同一账号并发触发风控）
     */
    public function queueFor(ManualPublicationAccount $account, Collection $articles): void { ... }
}
```

### 6.6 后台管理 UI

**新增页面**：
- `/admin/accounts` — 账号列表（搜索/筛选/分组/批量导入 CSV）
- `/admin/accounts/create` — 单账号创建向导
- `/admin/accounts/{id}/authorize` — 授权引导页（弹出 worker 授权窗口）
- `/admin/accounts/{id}/edit` — 账号配置（代理/UA/指纹/默认模式）
- `/admin/accounts/import` — CSV 批量导入（几十上百个账号）

**账号列表字段**：
```
| 头像 | 昵称 | 平台 | 分组 | 健康度 | 今日已发 | 失败连续 | 模式 | 操作 |
```

---

## 7. 端到端部署与配置流程（需求⑤）

### 7.1 安装步骤（按顺序执行即可）

#### 第 1 步：准备工作目录

```bash
# SSH 登录 GEOFlow 服务器
cd /opt/geoflow
ls  # 应该看到 geoflow-updater、docker-compose.prod.yml 等
```

#### 第 2 步：拉取 worker 代码

```bash
cd /opt/geoflow
git pull origin prod/v3.1.0-selfmedia
ls geoflow-browser-worker/   # 确认新目录已生成
```

#### 第 3 步：申请 CloakBrowser License（可选）

- 打开 https://cloakbrowser.dev/pricing
- 选 "Indie" 或 "Team"，付 $99/$499
- 收邮件得到 license key（一串 base64）
- **没有 license 可跳过**，降级模式可用但强度弱

#### 第 4 步：配置 `.env.prod`（GEOFlow 根目录）

```bash
cd /opt/geoflow
cp .env.prod .env.prod.bak-$(date +%Y%m%d)

# 在 .env.prod 末尾追加：
cat >> .env.prod <<'EOF'

# ============ 新增：geoflow-browser-worker ============
GEOFLOW_BROWSER_WORKER_ENABLED=true
GEOFLOW_BROWSER_WORKER_URL=http://geoflow-browser-worker:8081
GEOFLOW_BROWSER_WORKER_TOKEN=__CHANGE_ME__
GEOFLOW_BROWSER_WORKER_STORAGE_DIR=/var/lib/geoflow-browser-worker/storage_state
GEOFLOW_BROWSER_WORKER_HEADLESS=true
GEOFLOW_BROWSER_WORKER_MAX_CONCURRENT_PUBLICATIONS=8
GEOFLOW_BROWSER_WORKER_MAX_CONCURRENT_VISIBILITY=4

# CloakBrowser（留空 = 降级模式）
GEOFLOW_CLOAKBROWSER_LICENSE_KEY=
GEOFLOW_CLOAKBROWSER_BINARY_PATH=/opt/cloakbrowser/chromium-146.0.7680.177.5/chrome

# 代理（每账号独立代理 IP 推荐使用）
GEOFLOW_BROWSER_WORKER_DEFAULT_PROXY=
GEOFLOW_BROWSER_PROVIDER=                  # 留空或填代理服务商代号
EOF

# 生成 worker token
openssl rand -hex 32  # 把输出替换 __CHANGE_ME__
sed -i 's/__CHANGE_ME__/刚刚生成的值/' .env.prod
```

#### 第 5 步：worker 侧 `.env`（独立配置）

```bash
cd /opt/geoflow/geoflow-browser-worker
cp .env.example .env

# 关键配置项：
GEOFLOW_LARAVEL_URL=http://app:9000
GEOFLOW_BROWSER_WORKER_TOKEN=__与Laravel侧保持一致__
GEOFLOW_CLOAKBROWSER_LICENSE_KEY=__与Laravel侧保持一致__
GEOFLOW_STORAGE_STATE_DIR=/var/lib/geoflow-browser-worker/storage_state
PLAYWRIGHT_BROWSERS_PATH=/opt/playwright
```

#### 第 6 步：构建并启动 worker 容器

```bash
cd /opt/geoflow
docker compose -f docker-compose.prod.yml build browser-worker
docker compose -f docker-compose.prod.yml up -d browser-worker

# 检查启动日志
docker compose -f docker-compose.prod.yml logs -f browser-worker | head -50
# 应该看到：
# INFO:    Started server process [1]
# INFO:    Waiting for application startup.
# INFO:    Application startup complete.
# INFO:    Uvicorn running on http://0.0.0.0:8081
```

#### 第 7 步：Laravel 侧迁移 + 初始化

```bash
# SSH 进入 app 容器
docker compose -f docker-compose.prod.yml exec app bash

cd /var/www/html
php artisan migrate --force   # 自动跑新增的 5 个迁移
php artisan geoflow:browser-license-check  # license 检查
php artisan geoflow:browser-worker-health  # 健康检查

# 创建 worker 用的 access token
php artisan passport:client --name="browser-worker" --no-interaction
# 把生成的 client_id 和 client_secret 填到 worker 的 .env（后续可改用 PAT）
```

#### 第 8 步：注册 worker 进 Laravel 调度

```bash
# 编辑 routes/console.php，新增：
Schedule::call(function () {
    app(\App\Services\BrowserOperations\BrowserWorkerHealthCheck::class)->run();
})->everyFiveMinutes()->name('browser-worker-health');

Schedule::call(function () {
    app(\App\Services\BrowserOperations\AccountHealthAuditor::class)->run();
})->hourly()->name('account-health-audit');
```

#### 第 9 步：重启所有相关服务

```bash
cd /opt/geoflow
docker compose -f docker-compose.prod.yml restart app queue worker horizon
docker compose -f docker-compose.prod.yml restart browser-worker
```

#### 第 10 步：登录 GEOFlow 后台

```
URL: https://your-geoflow-domain.com/admin
路径：浏览器操作 → 浏览器 worker → 健康状态
期望：看到 geoflow-browser-worker 状态 = Healthy
```

### 7.2 配置账号步骤

#### 第 11 步：进入账号管理

```
后台 → 账号管理 → 新建账号
```

按向导填：
```
平台：[下拉选择 百家号]
昵称：博迈律所-主账号
分组：博迈律所-主力
代理 IP：http://user:pass@ip:port  （推荐每账号独立）
默认模式：[下拉 auto]（高信任账号用 auto，新账号先用 semi）
```

点"创建并创建账号"。

#### 第 12 步：首次授权

```
后台 → 账号管理 → 刚创建的账号 → 操作 → 首次授权
```

弹出 worker 授权窗口（headless 浏览器跑在 server 端）：
- 展示"百家号登录二维码"
- 用户用手机扫码
- worker 轮询检测 DOM（百家号选择器 `.UjPPKm89R4RrZTKhwG5H` 等已验证）
- 检测到登录态 → 自动抓 cookies + localStorage + sessionStorage
- 加密保存到 `/var/lib/geoflow-browser-worker/storage_state/{account_id}.json`
- 状态自动切到 "已授权"

#### 第 13 步：批量导入账号（几十上百个）

```
后台 → 账号管理 → 批量导入
```

下载 CSV 模板，格式：
```csv
platform,handle,group,proxy_url,default_mode,tags
bjh,博迈主号,博迈-主力,http://u:p@1.2.3.4:8888,auto,高价值主力
bjh,博迈副号,博迈-主力,http://u:p@1.2.3.5:8888,auto,
xhs,博迈-小红书,博迈-主力,http://u:p@1.2.3.6:8888,semi_auto,
zh,博迈-知乎,博迈-主力,http://u:p@1.2.3.7:8888,auto,
...
```

上传 → 自动逐个弹出授权窗口 → 扫码 → 入库。

#### 第 14 步：配置发布平台

```
后台 → 平台配置 → 各平台启用状态
```

对每个平台（百家号/公众号/头条/小红书/知乎/微博/抖音/搜狐/简书/CSDN/网易）：

| 配置项 | 说明 | 默认值 |
|---|---|---|
| **是否启用** | 开关 | true |
| **默认 worker 模式** | auto / semi_auto | auto |
| **批量并发数** | 同时跑几个 worker | 1（同一平台） |
| **图片适配器** | 自动选 | 见 §4.3 |
| **step_list 版本** | 平台改版时换 | 自动检测 |
| **冷却时间** | 同一账号两次发布最小间隔 | 60s |
| **日上限** | 单账号每日最多发布 | 百家号 5、公众号 1、抖音 3 |
| **异常重试** | 失败后自动重试 | 2 次 |

#### 第 15 步：触发首次发布

```
后台 → 内容工厂 → 文章列表 → 勾选一篇文章 → 顶部"批量发布"
```

弹窗：
```
发布模式：[● auto  一键自动] [○ semi  同步到平台待确认]
目标平台：[☑ 全部 11 个] / 自定义
账号选择：[● 账号组：博迈-主力] [○ 单独选择]
预览：先发布 1 篇测试 [☑]  （勾上后只跑 1 个平台验证）
```

点 "开始发布"。

#### 第 16 步：观察进度

```
后台 → 浏览器操作 → 工作单列表
```

看到每个工作单的状态：
```
| 文章标题        | 平台   | 账号       | 模式 | 状态      | 进度 | 链接 |
| 彩礼返还的 5 种 | 百家号 | 博迈主号    | auto | publishing | 60%  |  -  |
| 彩礼返还的 5 种 | 头条   | 博迈主号    | auto | completed  | 100% | https://... |
| 彩礼返还的 5 种 | 公众号 | 博迈主号    | auto | failed     |  -   |  -  | 失败原因：需要验证码
```

### 7.3 完整流程图

```
[管理员]
   │
   │ ① 准备工作：装 worker、配 .env、申请 license
   ▼
[服务器] ② docker compose up browser-worker
   │
   │ ③ php artisan migrate
   ▼
[后台] ④ 创建账号 → 批量导入 CSV
   │
   │ ⑤ 逐个账号扫码授权（worker headless 跑）
   ▼
[worker] ⑥ 抓 cookies → 加密落盘 → 上传 Laravel
   │
   │ ⑦ 写文章 / 调 GEOFlow 生成文章
   ▼
[后台] ⑧ 勾选文章 + 选模式 + 选账号组 + 点"一键发布"
   │
   │ ⑨ Laravel 把任务拆 N 份入 Redis 队列
   ▼
[queue worker] ⑩ 拉取任务 → 调 BrowserPublicationPublisher
   │
   │ ⑪ HTTP POST 到 geoflow-browser-worker/tasks
   ▼
[geoflow-browser-worker] ⑫ 启 Playwright → 加载 storage_state → 执行 step_list
   │
   │ ⑬ 上传配图 → 自动点 Publish → 拿公网 URL
   │
   │ ⑭ 写回结果到 Laravel
   ▼
[ManualPublication] ⑮ status=completed + completion_url + receipt
   │
   │ ⑯ 后台展示 + 企微通知
   ▼
[管理员] ⑰ 全程无需打开本地浏览器 ✅
```

---

## 8. 扩展性设计（需求⑥）

### 8.1 加新平台（3 步搞定，无需改 PHP 代码）

#### 步骤 1：写 step_list

```python
# geoflow-browser-worker/app/steps/new_platform.py
def step_list():
    return [
        {'type': 'navigate', 'selector': 'https://newplatform.com/editor', 'descript': '打开编辑器', 'is_wait': 10},
        # ... 根据真实 DOM 选择器写每一步
    ]

def selectors():
    """登录态检测选择器，给授权流程用"""
    return {
        'logged_in': '.user-avatar-class',
        'login_button': 'a[href*="/login"]',
    }
```

#### 步骤 2：注册平台常量

```php
// app/Models/ManualPublicationAccount.php
public const PLATFORM_NEWPLATFORM = 'newplatform';

// 在 PHPDoc 注释里更新 PLATFORM_* 列表
```

#### 步骤 3：注册路由 + UI

```php
// app/Services/SelfMedia/SelfMediaPlatformRouter.php
self::MAP['newplatform'] = [
    'publisher_class' => BrowserPublicationPublisher::class,
    'channel_type' => DistributionChannel::TYPE_BROWSER_WORKER,
    'step_module' => 'app.steps.newplatform',
];
```

#### 步骤 4（可选）：写平台专属配置

```yaml
# config/browser_worker_platforms.yaml
newplatform:
  enabled: true
  default_mode: auto
  daily_quota: 5
  cooldown_seconds: 60
  image_adapter: standard  # 或 custom
  step_list_version: 1.0.0
```

**完成**。新平台立即可用，**无需重启 Laravel 容器**（YAML 热加载），只需重启 worker。

### 8.2 加新账号（向导化）

```
后台 → 账号管理 → 新建账号 → 下拉选新平台 → 填昵称/代理 → 创建 → 扫码授权 → 完成
```

零代码改动。

### 8.3 加新 step 操作类型（程序员级扩展）

```python
# geoflow-browser-worker/app/executor/step.py  注册新操作
OPERATORS = {
    'navigate': op_navigate,
    'click': op_click,
    'fill': op_fill,
    # ...
    'my_new_op': op_my_new_op,  # 新增
}

async def op_my_new_op(step, context):
    # 自定义逻辑
    pass
```

### 8.4 step_list 自动适配平台改版

参考 `auth helper` 反编译出的 `sava_step_list` 机制：

```python
# worker 在执行过程中检测 DOM 改版（找不到 selector）→ 上报 Laravel
async def report_step_failure(platform, step, error):
    requests.post(f'{LARAVEL_URL}/api/browser-worker/step-failures', json={
        'platform': platform,
        'step': step,
        'error': str(error),
        'detected_at': datetime.utcnow().isoformat(),
    })
```

**管理员可见**：
```
后台 → 浏览器操作 → step_list 失效告警
| 平台 | 步骤 | 选择器 | 首次失败时间 | 失败次数 | 状态 |
| 百家号 | click | .publish-btn | 2026-09-15 03:21 | 5 | 待修复 |
```

修复方式：开发者更新 `app/steps/bjh.py` 的 `step_list()`，push 代码，worker 镜像自动构建，下发新版本。

### 8.5 配置热加载

```yaml
# config/browser_worker.yaml  监听变化
watch: true
reload_on_change: true
```

worker 用 `watchfiles` 监控文件变化，**修改配置后无需重启**。

---

## 9. 发布验证与监控（验证）

### 9.1 验证清单（端到端）

| 编号 | 验证项 | 通过标准 | 验证方法 |
|---|---|---|---|
| V1 | worker 启动 | 健康检查返回 200 | `curl http://worker:8081/healthz` |
| V2 | CloakBrowser license 有效 | license_check 返回 ok | `php artisan geoflow:browser-license-check` |
| V3 | 账号授权 | storage_state 文件生成、size > 1KB | `ls -la /var/lib/geoflow-browser-worker/storage_state/` |
| V4 | 单平台单账号发布 | ManualPublication.status=completed + completion_url 可访问 | 后台查看 + 浏览器访问 URL |
| V5 | 多平台并发发布 | 同一文章 11 个工作单全部 completed/failed，无 outcome_unknown | 后台列表筛选 |
| V6 | 图片自动上传 | 平台文章页有图、封面正确 | 截图对比 |
| V7 | 双模式切换 | auto 自动发布 / semi 填好但不发布 | 观察浏览器人工复核 |
| V8 | 多账号负载均衡 | 50 个账号都能用 | 批量导入后看健康度 |
| V9 | 熔断机制 | 同一账号连续失败 3 次后 cooldown | 故意触发失败 |
| V10 | 异常恢复 | worker 重启后任务自动续跑 | `docker restart` 后观察 |
| V11 | license 监控告警 | 提前 30 天邮件 | 模拟 license 过期 |
| V12 | 日志可观测 | Laravel 日志 + worker 日志齐全 | `docker logs` |

### 9.2 监控指标（关键 5 个）

```
GRAFANA 仪表盘（已有 GEOFlow Grafana 集成）：

1. 浏览器 worker 健康度
   - worker_up (0/1)
   - worker_active_tasks (gauge)
   - worker_queue_depth (gauge)

2. 发布成功率（按平台）
   - publish_success_rate (%)
   - publish_total_24h (count)
   - publish_failed_24h (count)

3. 账号健康度
   - accounts_total
   - accounts_healthy (%)
   - accounts_in_cooldown (count)
   - health_score_avg (0~100)

4. 反检测告警
   - captcha_triggered_24h (count)
   - ban_warning_24h (count)
   - license_expires_in_days

5. 业务流量
   - publications_in_flight
   - avg_publish_duration_seconds
   - p95_publish_duration_seconds
```

### 9.3 告警规则（已有的 AlertManager）

```yaml
# monitoring/alerts/browser_worker.yml
- alert: BrowserWorkerDown
  expr: worker_up == 0
  for: 5m
  severity: critical
  action: 企微告警 + 邮件

- alert: PublishSuccessRateLow
  expr: publish_success_rate < 80%
  for: 30m
  severity: warning
  action: 邮件

- alert: LicenseExpiresIn7Days
  expr: license_expires_in_days < 7
  severity: critical
  action: 企微 + 短信

- alert: AccountCooldownStorm
  expr: rate(accounts_in_cooldown[10m]) > 5
  severity: warning
  action: 邮件
```

### 9.4 失败排查手册（常见 5 类）

| 现象 | 可能原因 | 排查路径 |
|---|---|---|
| worker 起不来 | license 无效 / Python 依赖缺 | `docker logs browser-worker` 看 stacktrace |
| 授权失败 | DOM 改版 / 代理 IP 被封 | 看 worker 日志 `login_capture` 模块 |
| 上传图片失败 | 平台限流 / 图片体积超限 | 看 Pillow 处理日志 + 平台风控码 |
| 发布失败（点不到 Publish） | 平台改版 / selector 失效 | 看 step_list 失败告警 + 重跑 |
| 整账号被风控 | IP 被识别 / 行为异常 | 看 AccountHealthLog + 换代理 |

---

## 10. 风险与对策（与架构方案互补）

| 风险 | 影响 | 对策 |
|---|---|---|
| 平台 DOM 改版导致 step_list 失效 | 11 平台全发不出 | 自动失败上报 + 浏览器扩展作应急 + step_list 热更新机制 |
| CloakBrowser license 到期未续费 | 反检测降级 | 监控告警 + 自动降级到 stealth |
| worker 单点故障 | 全平台停滞 | docker compose replicas=2 + Redis 队列 |
| 账号被批量风控 | 多账号停摆 | 每账号独立代理 + 健康度评分 + 阶梯冷却 |
| 资金压力（$99 license） | 阻塞上线 | 先用降级 stealth 模式跑 1 个月，验证 ROI 后再升级 |
| 平台"作者实名认证"要求 | 部分账号必须人工 | 首次授权流程用浏览器扩展或人工，第二次起 worker 自动 |
| 大批量账号触发平台策略 | 全军覆没 | 引入"账号组发布节流"：同组 N 篇文章分批发布，模拟真人节奏 |

---

## 11. 改动清单（精确到文件路径）

### 11.1 新增（约 30 个文件）

```
GEOFlow-main/
├── app/Models/
│   ├── AccountGroup.php                          # 新
│   ├── AccountHealthLog.php                      # 新
│   ├── LicenseMonitor.php                        # 新
│   └── ManualPublicationModePreference.php       # 新
├── app/Services/BrowserOperations/
│   ├── BrowserPublicationPublisher.php           # 新（已规划于架构方案）
│   ├── BrowserWorkerClient.php                   # 新
│   ├── ModeAwarePublisher.php                    # 新
│   ├── AccountLifecycleService.php               # 新
│   ├── AccountCircuitBreaker.php                 # 新
│   ├── AccountBatchRouter.php                    # 新
│   └── BrowserWorkerHealthCheck.php              # 新
├── app/Services/GeoFlow/AiVisibility/
│   ├── AiVisibilityProviderInterface.php         # 新
│   └── Providers/PlaywrightWebVisibilityProvider.php  # 新
├── app/Console/Commands/
│   ├── GeoflowBrowserWorkerHealthCommand.php     # 新
│   ├── GeoflowBrowserLicenseCheckCommand.php     # 新
│   └── GeoflowAccountHealthAuditCommand.php      # 新
├── app/Http/Controllers/Admin/
│   ├── AccountController.php                     # 新（CRUD）
│   ├── AccountGroupController.php                # 新
│   └── AccountBatchImportController.php             # 新（CSV）
├── app/Http/Controllers/Api/V1/
│   └── BrowserWorkerCallbackController.php       # 新
├── database/migrations/
│   ├── 2026_09_12_000000_create_account_groups_table.php
│   ├── 2026_09_12_000001_add_browser_fields_to_manual_publication_accounts.php
│   ├── 2026_09_12_000002_create_account_health_logs_table.php
│   ├── 2026_09_12_000003_add_publication_mode_to_manual_publications.php
│   └── 2026_09_12_000004_create_manual_publication_mode_preferences.php
├── config/
│   ├── browser_worker.php                        # 新
│   └── browser_worker_platforms.yaml             # 新（平台配置）
├── resources/views/admin/accounts/               # 新（账号管理 UI）
│   ├── index.blade.php
│   ├── create.blade.php
│   ├── edit.blade.php
│   ├── authorize.blade.php
│   └── import.blade.php
├── docs/plans/2026-09-12-one-click-publication.md  # 本文档
└── docs/runbooks/browser-worker-runbook.md        # 新（运维手册）

geoflow-browser-worker/                            # 新目录（约 25 个文件）
├── Dockerfile
├── pyproject.toml
├── .env.example
├── README.md
├── app/
│   ├── main.py
│   ├── config.py
│   ├── tasks.py
│   ├── callback.py
│   ├── result_normalizer.py
│   ├── executor/
│   │   ├── browser.py
│   │   ├── step.py
│   │   └── selector.py
│   ├── steps/
│   │   ├── bjh.py
│   │   ├── sohu.py
│   │   ├── zhihu_column.py
│   │   ├── toutiao.py
│   │   ├── csdn.py
│   │   ├── jianshu.py
│   │   ├── netease.py
│   │   ├── qq_penguin.py
│   │   ├── dayu.py
│   │   ├── douyin.py
│   │   ├── weibo.py
│   │   ├── xhs.py
│   │   ├── ai/kimi.py
│   │   ├── ai/zhipu.py
│   │   ├── ai/wenxin.py
│   │   ├── ai/doubao_web.py
│   │   ├── ai/deepseek_web.py
│   │   └── ai/gemini.py
│   ├── auth/
│   │   ├── login_capture.py
│   │   └── storage.py
│   └── media/
│       ├── preprocessor.py
│       ├── adapters.py
│       ├── grid.py
│       ├── video_cover.py
│       └── uploader.py
└── tests/
    └── ...
```

### 11.2 修改（约 12 个文件）

```
GEOFlow-main/
├── docker-compose.prod.yml                                # + browser-worker service
├── geoflow-updater                                        # 二进制（enroll --with-browser-worker）
├── .env.prod.example                                      # + worker 配置示例
├── app/Models/ManualPublication.php                       # + mode 字段
├── app/Models/ManualPublicationAccount.php                # + 14 字段
├── app/Models/DistributionChannel.php                     # + TYPE_BROWSER_WORKER
├── app/Services/GeoFlow/DistributionPublisherManager.php  # 注册新 publisher
├── app/Services/SelfMedia/SelfMediaPlatformRouter.php     # 11 平台映射
├── app/Services/SelfMedia/SelfMediaBatchService.php       # BROWSER_CONCURRENCY 1→8
├── app/Services/GeoFlow/AiVisibility/AiVisibilityCollectionService.php  # + web 分支
├── routes/web.php                                         # + /admin/accounts 路由
├── routes/api.php                                         # + /api/browser-worker/* 路由
└── routes/console.php                                     # + 2 个调度任务
```

### 11.3 不动但功能升级

```
GEOFlow-main/browser-extension/                           # 降级为只读 + 首次授权工具
```

---

## 12. 落地分期（推荐 4 个里程碑，与架构方案对齐）

| 里程碑 | 时长 | 内容 | 验证 |
|---|---|---|---|
| **M1** | 1~2 周 | worker 骨架 + 头条端到端 + 单账号 auto 模式 + 图文上传 | V1、V2、V4、V6 |
| **M2** | 2~3 周 | 11 平台全部接入 + 双模式切换 + 多账号管理 | V5、V7、V8 |
| **M3** | 2 周 | 网页版 AI 平台自动采集 + 熔断机制 + license 监控 | V9、V11 |
| **M4** | 1 周 | 一键触发 + CSV 批量导入 + Grafana 监控 + 运维手册 | V10、V12 |

**总计**：6~8 周（单人开发）

---

## 13. 预算估算（按需）

| 项 | 一次性 | 年度 |
|---|---|---|
| CloakBrowser Indie license | — | $99 |
| 代理 IP（50 账号，每账号独立 IP） | — | ~$300~600 |
| 服务器升级（多 worker + 反检测） | — | ~$200~400 |
| 开发人力（6~8 周 × 1 人） | ¥30,000~50,000 | — |
| **首年合计** | **¥30k~50k** | **$600~1100** |

---

## 14. 需要你拍板的关键决策

1. **CloakBrowser license 预算**
   - 选 A：直接买 $99/年 Indie license（首选，反检测强度高）
   - 选 B：先用免费的 stealth 降级方案跑 1 个月，验证 ROI 后再决定
   - 选 C：彻底不花钱，依赖免费 stealth + 每账号独立代理（强度弱，有封号风险）

2. **多账号规模**
   - 选 A：先跑通 1~3 个核心账号（百家号 + 头条 + 公众号）
   - 选 B：直接规划 50~100 个账号矩阵
   - 影响：决定账号管理 UI 是否需要"分组 / 批量导入 / 健康度大盘"

3. **部署方式**
   - 选 A：跟现有 GEOFlow 一起部署在同一台服务器（节省成本）
   - 选 B：worker 单独一台机器（隔离干净，更稳）
   - 影响：docker-compose 配置不同

4. **改造节奏**
   - 选 A：按 M1→M4 顺序，先头条端到端
   - 选 B：先做 M3（AI 网页版采集，痛点更直接）
   - 选 C：M1 + M2 并行，全平台同时上

明确 4 项后，我可以直接进入 **M1 实施**（worker 骨架 + 头条端到端 + auto 模式），预计 1~2 周交付。

---

## 15. 附录：与原架构方案的边界

本文档严格基于 `2026-09-11-browser-worker-architecture.md` 的总架构，**不重复**总架构已写清楚的章节，专注补齐 6 个具体需求的工程细节：
- §3 双模式 → 补充状态机、字段、UI
- §4 图片上传 → 补充具体代码、9 宫格切图
- §5 CloakBrowser → 补充替代方案对比、降级路径
- §7 账号管理 → 补充完整模型 + 熔断策略
- §8 完整配置流程 → 补充 16 步安装向导
- §8 扩展性 → 补充加新平台 3 步流程
- §9 验证 → 补充 12 项验收清单 + 5 个监控指标

如有任何细节需要展开（例如 step_list 的反编译产物迁移、CloakBrowser license 申请具体步骤、CloakBrowser 与 puppeteer-extra-stealth 的具体对比测试），告诉我具体方向，我可以再展开。