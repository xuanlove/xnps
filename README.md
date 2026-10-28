<div align="center">

# xnps

**基于 nps 的高性能内网穿透与集中管理平台（增强分支）**

`Go 内核（nps-admin-ext）` · `Laravel 集中管理端（nps-admin）` · `全平台二进制`

[上游项目](https://github.com/ehang-io/nps) · [设计文档](DESIGN.md) · [源码解析](PROJECT_DIARY.md) · [交付总结](DELIVERY.md) · [审计报告](AUDIT_REPORT.md)

</div>

---

## 致敬原作者（必读）

**xnps 不是原创项目，而是 [ehang-io/nps](https://github.com/ehang-io/nps) 的衍生分支（fork）。**

- 上游项目：**nps** —— 一款轻量、高性能、功能强大的内网穿透代理服务器，由 **ehang-io** 及全体贡献者开发，以 **GPL-3.0** 协议开源发布。
- 本项目全部核心能力（bridge 通信、协议、隧道模式、Web 管理界面、客户端 npc）均来自上游项目的卓越工作。
- 我们向原作者与所有贡献者致以诚挚谢意。没有 nps，就没有 xnps。
- 依据 GPL-3.0 协议要求，本衍生项目同样以 **GPL-3.0** 开源，并在下文完整公开全部修改记录。
- 上游仓库已归档停止维护，xnps 在其 v0.26.10 基线上以"最小侵入"原则持续维护增强能力。

> 如果你只需要一个纯粹的内网穿透工具，请优先使用原版 [nps](https://github.com/ehang-io/nps)。xnps 面向的是"多台 nps 服务器集中纳管 + 运营化（审批/计费/证书/升级）"场景。

## 项目组成

| 组件 | 技术栈 | 说明 |
|---|---|---|
| **xnps**（服务端内核） | Go 1.26+ | 上游 nps 0.26.10 + `nps-admin-ext` 扩展模块（见修改记录） |
| **xnpc**（客户端） | Go | 与上游 npc 功能一致（同源构建，改名随包发布） |
| **nps-admin**（集中管理端） | Laravel 12 / PHP 8.2+ | 多服务器纳管、三层同步、自助门户、审批/计费/证书/滚动升级（独立新增组件，不含上游代码） |

## xnps 增强了什么

在上游 nps 之上，`nps-admin-ext` 扩展模块（Web API `/ext/*`）解决原版在**大规模 + 集中管理**场景下的痛点：

- **轻量监控** `/ext/system`：O(1) 系统指标（原版 dashboard 为 O(C×T) 且有数据竞争），10 万级 client 下实测 0.1–1ms。
- **变更日志** `/ext/version` + `/ext/changes`：内存环形变更日志（容量 1 万条），集中端每 10s 轮询 8 字节级版本号，变化才拉增量——替代高频轮询原版 `/client/list`（原版 20 万隧道实测 >60s 超时，`/ext/list` 游标分页 <32ms）。
- **在线/流量通道** `/ext/online`、`/ext/flow`（16 分片轮转采集，O(T+H)/片）。
- **运维闭环** `/ext/config`（白名单配置读写 + 原子写 + 备份）、`/ext/cert`（证书下发/指纹对账，激活唯一入口）、`/ext/restart`（202 + 延迟触发）、`/ext/upgrade`（inline/url 双模式，sha256 强校验，仅 HTTPS + 内网地址防护，rename 原子替换 + 本地双份备份）。
- **集中管理端能力**：三层同步（L1 写后回读 / L2 10s 增量 / L3 6h 全量对账）、Saga 写补偿、端口池预占、审批流、月度配额计费、证书全生命周期、灰度滚动升级。

### 性能实测（10 万 client / 20 万 tunnel）

| 指标 | 结果 |
|---|---|
| ext 各端点延迟 | 0.1 – 32 ms |
| 原版 `/client/list`（同规模对照） | >60s 超时 |
| L2 端到端同步延迟 | 64 ms |
| L3 全量对账（30 万行镜像导入） | 7m32s |

完整数据见 [DELIVERY.md](DELIVERY.md)，验收方法见 [DESIGN.md §6.2](DESIGN.md)。

## 快速开始

### 服务端（xnps）

```bash
# 以 linux_amd64 为例，其余平台见 dist/ 发布包
tar -zxvf server-0.26.10-ext0.3.0-linux-amd64.tar.gz && cd server-0.26.10-ext0.3.0-linux-amd64
# 编辑 conf/nps.conf（务必修改 auth_key / web_username / web_password）
sudo ./xnps start        # 注册为系统服务并启动（支持 install/start/stop/restart/uninstall）
sudo ./xnps              # 或前台运行
```

### 客户端（xnpc）

```bash
./xnpc -server=1.2.3.4:8024 -vkey=<客户端vkey>
```

### 集中管理端（nps-admin，可选）

```bash
cd nps-admin
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --force
# 创建管理员（管理控制台登录）
php artisan nps:admin admin@example.com
# 注册一台 nps 服务器（CLI 免登录）
php artisan nps:register <名称> http://<nps地址>:<web端口> <auth_key>
php artisan queue:work --queue=nps-sync   # 同步队列（另有 nps-provision / nps-upgrade）
php artisan schedule:work                 # 秒级调度（10s/30s）；或 cron schedule:run（需 flock 防重叠）
```

**管理控制台**：构建前端后访问 `http://<管理端>/admin`

```bash
cd nps-admin && npm install && npm run build
# 生产环境建议设 ASSET_URL=/（反向代理/多域名下避免跨域绝对资源 URL）
```

控制台功能：仪表盘、服务器（探活/同步/配置/证书）、客户端、隧道、域名解析、审批、计费与支付（套餐/钱包/账单/支付流水）、证书（上传/certd/内置 ACME/部署/HTTPS/轮换/对账）、版本升级（制品/灰度/回滚）、监控告警与指标曲线。

完整生产部署（PHP-FPM/Nginx、Supervisor 队列、调度、权限、支付与 ACME 配置、备份与故障排查）见 [ADMIN_DEPLOYMENT.md](ADMIN_DEPLOYMENT.md)。

### 支付渠道（支付宝 / 微信支付 v3）

在 `nps-admin/.env` 配置（详见 `config/payments.php`）：

```ini
PAYMENT_DEFAULT_CHANNEL=alipay        # alipay | wechat | mock
ALIPAY_ENABLED=true
ALIPAY_APP_ID=...
ALIPAY_PRIVATE_KEY=...                # PEM 内容（\n 转义）或文件路径
ALIPAY_PUBLIC_KEY=...
ALIPAY_NOTIFY_URL=https://<管理端>/api/payments/alipay/notify

WECHAT_PAY_ENABLED=true
WECHAT_PAY_APP_ID=...
WECHAT_PAY_MCH_ID=...
WECHAT_PAY_SERIAL_NO=...
WECHAT_PAY_PRIVATE_KEY=...
WECHAT_PAY_API_V3_KEY=...             # 32 字节
WECHAT_PAY_PLATFORM_CERT=...          # 微信支付平台证书（PEM）
WECHAT_PAY_NOTIFY_URL=https://<管理端>/api/payments/wechat/notify
```

- 门户用户通过 `POST /api/portal/payments` 创建充值/账单支付订单，返回二维码内容；回调验签后幂等入账（充值入钱包、账单支付标记已付）。
- `mock` 渠道仅用于开发联调（生产默认关闭），可通过 `POST /api/portal/payments/{id}/mock-pay` 模拟到账。
- 回调端点公开但强制验签：支付宝 RSA2、微信支付 v3（平台证书验签 + APIv3 Key AES-256-GCM 解密）。

### 证书来源（certd / 内置 ACME）

- **certd**：`NPS_ACME_MODE=certd`，`NPS_ACME_CERTD_URL` + `NPS_ACME_CERTD_TOKEN`，由 certd 完成 DNS-01（支持通配符）。
- **内置 ACME**：`NPS_ACME_MODE=builtin`，由 nps 自身完成 HTTP-01（`golang.org/x/crypto/acme`）：
  - 要求目标域名解析到该 nps，且 `http_proxy_port`（通常 80）公网可达；
  - 不支持通配符；挑战由 nps HTTP 监听器的 `/.well-known/acme-challenge/` 应答；
  - 证书私钥在 nps 侧生成后回传管理端入库（私钥加密存储），部署仍走 `/ext/cert` 唯一入口；
  - `NPS_ACME_DIRECTORY_URL` 仅允许 Let's Encrypt 生产/测试目录（防 SSRF），首次建议先用 staging。
- 管理端「证书」页可签发、部署、启用 HTTPS（防自锁顺序）、轮换与全平台指纹对账；ACME 证书到期前 30 天自动续期并逐台滚动部署（manual 证书仅告警）。

## 构建与打包

```bash
./build-xnps.sh          # 全平台构建 + 打包，产物输出 dist/
./build-xnps.sh linux amd64   # 仅构建单平台（参数 GOOS GOARCH）
```

发布包命名：`{server|client}-{版本}-{GOOS}-{GOARCH}[-vN].tar.gz`，server 包内含 `xnps` 二进制、`conf/` 默认配置、`web/views`、`web/static`、`LICENSE`、`RELEASE.md`；client 包内含 `xnpc` 与 `conf/npc.conf`。

**供应链检查**（I2 安全基线，CI 已固化于 `.github/workflows/ci.yml`）：

```bash
govulncheck ./server/... ./web/... ./lib/... ./cmd/nps   # 调用路径漏洞扫描（当前 0）
(cd nps-admin && composer audit --no-dev)                 # PHP 依赖公告（当前无）
syft scan dir:. -o cyclonedx-json=sbom/xnps.cdx.json      # SBOM（Go/PHP/npm 组件清单）
```

## 版本命名

```
{上游基线版本}-ext{扩展模块版本}    例如 0.26.10-ext0.3.0
```

- 上游基线版本（`lib/version`）：跟随上游协议兼容性，npc 握手校验以 `core_version`（最低兼容 0.26.0）为准；
- 扩展模块版本（`server/ext` 的 `ExtVersion`）：xnps 自身能力演进版本，集中管理端据此做能力探测与升级签名校验。

> 说明：Go module 路径保持 `ehang.io/nps` 不变——这是刻意为之，便于与上游同步补丁，且不影响产品名与二进制名。

## 修改记录（Modifications）

依据 GPL-3.0 第 5(a) 条完整披露。原则：**能新增不修改**；仅有的 2 处语义级修改均因正确性缺陷（详见 [AUDIT_REPORT.md](AUDIT_REPORT.md)）。

### 第 1 轮：nps-admin-ext 扩展模块（纯新增，13 文件 +1338 行）

| 文件 | 类型 | 说明 |
|---|---|---|
| `lib/changelog/changelog.go`(+`_test.go`) | 新增 | 内存环形变更日志（seq 单调、回绕检测） |
| `server/ext/service.go` | 新增 | 版本常量、systemd/execve 重启 |
| `server/ext/metrics.go` | 新增 | O(1) 系统监控指标 |
| `server/ext/config.go` | 新增 | nps.conf 白名单读写（原子写 + 备份 + 脱敏） |
| `server/ext/cert.go` | 新增 | 证书校验落盘 / 指纹查询 |
| `server/ext/upgrade.go` | 新增 | 二进制升级（sha256 校验 + 原子替换） |
| `web/controllers/ext.go` | 新增 | `/ext/*` 10 个端点（强制 admin） |
| `lib/file/db.go` | 侵入 +9 行 | 8 处 `changelog.Append`（均位于持久化成功路径） |
| `web/controllers/index.go` | 侵入 +2 行 | EditHost 成功路径 1 处 Append |
| `web/controllers/client.go` | 侵入 +2 行 | ChangeStatus 成功路径 1 处 Append |
| `web/routers/router.go` | 侵入 +2 行 | ExtController 路由注册（两个分支） |
| `cmd/nps/nps.go` | 侵入 +2 行 | service 实例注入 |

### 第 2 轮：安全审计修复（2026-10-28）

| 文件 | 修改 | 目的 |
|---|---|---|
| `web/controllers/base.go` | +7 行 `hasParam()` | 参数显式性判断 |
| `web/controllers/client.go` | **语义补丁**：`/client/edit` 由"全量替换"改为"显式传入才修改" | 修复部分更新清空 vkey/Basic 认证/限额的安全缺陷 |
| `lib/changelog/changelog.go` | seq 分配移入锁内 | 修复并发乱序导致增量同步漏事件 |
| `server/ext/config.go` | 写回/备份权限 0644 → 0600 | 敏感配置文件不可同机其他用户可读 |
| `server/ext/upgrade.go` | 替换前本地双份备份 `.bak-ext` | 升级失败可带外自救 |
| `web/static/page/languages.xml` | UI 品牌 NPS → XNPS | 产品更名 |

### 第 3 轮：项目更名与发布工程

- 新增 `build-xnps.sh`（全平台构建打包脚本）、本 README、各发布包 `RELEASE.md`；
- `ExtVersion` 0.1.0 → 0.2.0（随第 2 轮行为变更递增）；
- **nps-admin/**（Laravel 集中管理端）为全新独立组件，不属于上游代码。

### 第 4 轮：管理控制台 / 支付 / 内置 ACME（ext 0.3.0）

| 文件 / 模块 | 类型 | 说明 |
|---|---|---|
| `server/ext/acme.go`、`lib/acmechallenge/` | 新增 | 内置 ACME（Let's Encrypt HTTP-01）；挑战由 HTTP 监听器应答 |
| `web/controllers/ext.go`、`web/routers/router.go` | 侵入 | 新增 `POST /ext/acme/issue`（2 行路由 + 1 个方法） |
| `server/proxy/http.go` | 侵入 3 行 | HTTP-01 挑战路径优先于 host 路由 |
| `nps-admin/resources/js/admin/`、`admin.blade.php`、`admin.css` | 新增 | Vue 3 管理控制台（10 个页面） |
| `nps-admin/app/Services/Payments/` | 新增 | 支付宝 RSA2 / 微信支付 v3（验签 + AES-GCM 解密）/ mock 驱动 |
| `nps-admin/app/Services/Cert/BuiltinAcmeProvider.php` | 新增 | 调 `/ext/acme/issue` 并入库 |
| `nps-admin/app/Console/Commands/CreateAdminCommand.php` | 新增 | `php artisan nps:admin` 创建管理员 |
| `nps-admin/database/migrations/2026_10_29_000001_create_payments_table.php` | 新增 | 支付订单表 |
| 升级编排 | 修复 | 异步 Run 复用（不再重复创建）、版本签名口径统一、制品版本字符白名单 |

### 未修改的已知上游缺陷

为保持与上游可合并性，以下上游既有问题未在内核修复（集中管理端已绕行或缓解）：`/index/start` 虚报成功、`/client/del` 级联删除、`/client/list` O(C×T)、`flow_limit` 生命周期累计语义、`lib/rate` ticker 无 stop 分支、p2p 无锁 map 等。完整清单见 [PROJECT_DIARY.md §15](PROJECT_DIARY.md)。

## 目录结构

```
├── cmd/nps/            # 服务端入口（构建产物 xnps）
├── cmd/npc/            # 客户端入口（构建产物 xnpc，与上游同源）
├── server/ext/         # nps-admin-ext 扩展模块（xnps 核心增量）
├── lib/changelog/      # 变更日志
├── web/                # 管理界面（上游 + /ext/* 控制器）
├── nps-admin/          # Laravel 集中管理端（独立组件）
├── build-xnps.sh       # 全平台构建打包脚本
├── DESIGN.md           # v3 设计文档（16 章）
├── PROJECT_DIARY.md    # 上游源码解析 + 既有缺陷清单
├── DELIVERY.md         # 交付总结与压测验收
└── AUDIT_REPORT.md     # 安全审计报告 + 修复记录
```

## 许可证

本项目基于 [ehang-io/nps](https://github.com/ehang-io/nps) 衍生，遵循 [GPL-3.0](LICENSE) 开源。所有修改同样以 GPL-3.0 发布。

## 免责声明

本项目仅供学习与合法授权的网络管理使用。请勿用于任何未授权的用途，使用者需自行承担使用风险。
