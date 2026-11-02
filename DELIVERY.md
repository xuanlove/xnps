# xnps 集中管理平台 · 项目交付总结

> 交付日期：2026-11-02  
> 交付基线：`master` @ `75804d1`（I3）；I3.1 收口已完成、待合并  
> 版本标识：**xnps 0.26.10-ext0.3.0** / **nps-admin 0.4.0**  
> 上游基线：ehang-io/nps 0.26.10（GPL-3.0，修改记录见 [README.md](README.md)）

---

## 1. 项目概述

xnps 是 ehang-io/nps 的 GPL-3.0 衍生分支：在保持原有内网穿透数据面的前提下，新增 `nps-admin-ext` 扩展模块与 Laravel 集中管理平面，解决原版单机面板在**多服务器纳管、用户自助、计费运营、证书生命周期、滚动升级、可观测性**上的空白。

**交付目标**：

- 纳管 50–100 台 nps，平台总量 10 万级 client / tunnel；
- 全链路仅经 WebAPI 控制，不依赖 SSH/Ansible 带外通道；
- 用户永不可见 nps `auth_key`；
- 管理端可独立部署（Laravel 12 + MySQL），nps 侧保持轻量。

**当前结论**：核心功能、管理控制台、支付、证书、升级、可观测性与运维工具均已交付，具备对外试运行条件；计费闭环（套餐变更/发票/退款/对账）与部分产品化能力列入 I4。

---

## 2. 交付范围

### 2.1 xnps 内核（Go）

- 规模：`server/ext`、`lib/changelog`、`lib/acmechallenge`、`web/controllers/ext.go` 共 **15 文件 / 2247 行**。
- 端点：

| 端点 | 能力 |
|---|---|
| `/ext/version` | 变更序号 + 内核/ext 版本，10s 增量探测 |
| `/ext/changes` | 环形变更日志（1 万条，回绕 `truncated`） |
| `/ext/online` | 在线 client id 集合 |
| `/ext/flow` | 16 分片流量聚合，O(T+H)/片 |
| `/ext/list` | 游标分页（client/tunnel/host），不做 Flow 聚合 |
| `/ext/system` | CPU/内存/负载/连接数/磁盘/fd/goroutine 峰值 |
| `/ext/config` | nps.conf 白名单读写、敏感键脱敏、备份 + 原子写 |
| `/ext/cert` | 证书下发/指纹对账（激活唯一入口） |
| `/ext/restart` | 202 + 延迟重启（Control 失败回退 execve） |
| `/ext/upgrade` | inline/url 升级，sha256 + ELF/PE/Mach-O 架构校验，rename 原子替换 |
| `/ext/acme/issue` | 内置 ACME HTTP-01 签发（仅 LE 生产/测试目录） |

- 安全：签名鉴权 ±20s、强制 admin、升级仅 HTTPS 且默认拦截内网/回环地址、证书路径限制在 `conf/`。

### 2.2 nps-admin 管理端（Laravel）

- 规模：`app/` **89 PHP 文件 / 8016 行**，**30 个迁移**，**20 个测试文件**。
- 核心能力：
  - 三层同步：L1 写后回读 / L2 10s 版本增量 / L3 6h 全量对账；
  - Saga 写补偿、端口池预占、`run_status` 回读校验；
  - 审批状态机 + 自动审批规则 + 幂等开通（`operation_key`/`resource_id`）；
  - 套餐/订阅/钱包/账单/流量基线/月度结算；
  - 证书库（私钥 encrypted）、certd/native 双通道、内置 ACME、自动续期、HTTPS 防自锁编排、指纹对账；
  - 制品库 + 灰度滚动升级 + 失败自动回滚；
  - 告警生命周期（去抖/静默/确认/自动恢复/webhook 退避）；
  - 审计日志（请求/响应脱敏）。
- 计费口径：nps `flow_limit` 是生命周期累计值，管理端以月度基线 + 增量累加实现月度配额，支持 throttle/disable/bill 三种超额动作。

### 2.3 管理控制台（Vue 3 + Vite + Tailwind）

11 个页面：仪表盘、服务器、客户端、隧道、域名、审批、计费与支付、证书、升级、告警、登录。构建产物由 `ASSET_URL=/` 支持反向代理与多域名部署。

### 2.4 支付

- 支付宝当面付（RSA2 下单/回调验签）、微信支付 v3 Native（平台证书验签 + APIv3 Key AES-256-GCM）、mock 渠道；
- 支付订单幂等入账、金额核对、回调验签、账单支付与钱包充值；
- 真实商户号联调与退款/发票/对账报表待 I4。

### 2.5 可观测性与运维

- `/healthz`：DB/缓存/队列/storage 四项探针，200/503，不泄露异常详情；
- `/metrics`：同步延迟、队列积压、API 错误率、计费耗时、升级状态、告警数（Prometheus 文本格式，支持 token）；
- 结构化日志：生产默认 JSON、`X-Request-Id`、慢查询、同步链路 `server_id/job` 上下文；
- 告警：可配置去抖窗口、静默、确认/解决、条件恢复自动 resolve、webhook 5 次指数退避；
- 运维资产：Docker Compose（db/nps/init/admin/queue/scheduler）、systemd 单元、备份/恢复脚本、Grafana 面板、`/ext` 压测工具。

### 2.6 工程与安全

- CI：Go vet/test、govulncheck、PHPUnit（SQLite + MySQL 8）、Vue 构建、Docker/Compose 校验；
- 发布：`build-xnps.sh` 14 平台 × server/client，sha256 + `nps-admin` 源码包；
- 供应链：Go 1.26、`govulncheck` 调用路径 0 漏洞、`composer audit` 无公告、CycloneDX SBOM；
- 安全加固：SSRF、HTTPS 强制、证书路径限制、制品 token 过期/轮换、abilities 隔离、限流、密码策略、审计脱敏。

---

## 3. 系统架构

```
Laravel 管理平面
  ├─ Sanctum + Policy（admin / portal 能力隔离）
  ├─ NpsApiClient（md5 签名 + 时钟校准 + 重试 + 审计）
  ├─ SyncEngine（L1/L2/L3 + online/flow）
  ├─ ProvisioningService（Saga + 端口池）
  ├─ BillingService（基线/配额/账单/钱包）
  ├─ CertificateService / HttpsProvisioner
  ├─ UpgradeOrchestrator（预检/canary/回滚）
  ├─ AlertService（统一告警入口）
  └─ /healthz + /metrics + JSON 日志
        │ HTTPS + auth_key 签名
        ▼
  xnps（nps 0.26.10 + ext 0.3.0） × N 台
```

---

## 4. 迭代交付记录

| 迭代 | 提交 | 主要内容 |
|---|---|---|
| I0 | `2d3522b` | 移除 Job 自循环/种子，改 `schedule:work` 节拍 + `ShouldBeUnique`；写操作只重试幂等端点 |
| I1 | `7d915ed` | 证书自动续期、升级流式下载/架构校验、流量与在线批量、端口池候选、对账空集清理、审批 CAS |
| I2 | `6cc9c57` | SSRF/HTTPS/证书路径/token 过期/流式下载、abilities/限流/密码策略、审计脱敏、Go 1.26 + x/net/xz 升级、SBOM |
| I3 | `75804d1` | `/healthz`、`/metrics`、`/ext/system` 磁盘/fd/峰值、告警生命周期、JSON 日志、Compose/systemd/备份/Grafana、CI 重建、压测工具 |
| I3.1 | agent 分支 `e4cdc69`（待合并） | 告警统一 `AlertService`；升级 Run skipped 统计、canary 观察复检、回滚制品口径；CI 增加 Vue/MySQL/Docker；真实 nps 宕机告警/恢复演练 |

---

## 5. 质量与验证

### 5.1 自动化测试与扫描

| 项 | 结果 |
|---|---|
| PHPUnit | **44 项 / 156 断言通过**（本地 PHP 8.5；CI 配置覆盖 SQLite 与 MySQL 8） |
| Go | **21 个测试函数**通过（server/ext、lib/acmechallenge、lib/changelog） |
| `go vet` | 扩展包通过 |
| `govulncheck` | 调用路径 **0 漏洞**；仅 x/crypto 1 项无修复公告且不可达 |
| `composer audit` | 无安全公告（含 CVE-2026-45070 修复） |
| SBOM | `sbom/xnps.cdx.json`，169 个组件（Go 53 / PHP 78 / npm 25） |
| 配置校验 | `config:cache`、route:list、Compose/Workflow/Grafana JSON 均通过 |

### 5.2 运维演练（2026-11-01）

真实 xnps 实例 + nps-admin：

1. 停止 nps → `is_reachable=0`；
2. `checkAll()` 产生 `unreachable/critical` 告警（open）；
3. 重启 nps → `is_reachable=1`；
4. 再次 `checkAll()` → 告警自动 `resolved`；
5. `/healthz` 200 且带 `X-Request-Id`；`/metrics` 输出服务器/同步/队列序列。

### 5.3 压测与性能基线

管理面回归（`scripts/loadtest`，本地真实 nps）：

| 指标 | 结果 |
|---|---|
| 请求数 | 31,262（6s / 8 并发） |
| 错误 | 0 |
| RPS | 5,210 |
| P50 / P95 / P99 | 0.58 / 5.56 / 9.11 ms |

历史 10 万 client / 20 万 tunnel 数据面基线（继承阶段 5 验收）：

| 指标 | 实测 |
|---|---|
| ext 端点延迟 | 0.1–32 ms |
| 原版 `/client/list` | >60s 超时（O(C×T) 设计论点实证） |
| L2 同步延迟 | 64 ms |
| L3 全量对账（30 万行） | 7m32s（6h 周期内） |
| online 10 万行 diff | 7.2s（30s 周期内） |

> 数据面重连风暴、100% 真实 npc 规模压测仍需独立压测机；当前结论用于容量规划与回归对比。

---

## 6. 部署与运维

### 6.1 源码部署

```bash
cd nps-admin
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
php artisan migrate --force
php artisan nps:admin admin@example.com
php artisan nps:register <name> http://<nps>:<web端口> <auth_key>
# 常驻：queue:work --queue=nps-sync,nps-provision,nps-upgrade + schedule:work
```

前端：`npm ci && npm run build`；生产设置 `ASSET_URL=/`。

### 6.2 Docker Compose（开发/演示）

```bash
docker compose up -d --build
# admin: http://localhost:8000/admin
# health: /healthz   metrics: /metrics
```

默认账号/口令仅用于开发（`admin@example.com / admin12345`、`NPS_AUTH_KEY=xnps-docker-dev-key`），生产必须覆盖。

### 6.3 systemd / 备份

- `deploy/systemd/`：xnps、scheduler、queue 模板；
- `deploy/backup.sh`：DB + `storage/app` + 可选 nps conf/.env，带保留期与 sha256；
- `deploy/restore.sh`：校验和、数据库与制品库恢复；
- 完整生产说明见 [ADMIN_DEPLOYMENT.md](ADMIN_DEPLOYMENT.md)。

### 6.4 发布物

GitHub Release `v0.26.10-ext0.3.0`：

- 30 个资产，145.9 MB；
- 14 平台 × server/client 包 + `nps-admin` 源码包 + `sha256sums.txt`；
- 发布流水线已改为复用 CI 门禁 + `build-xnps.sh` 全平台构建。

---

## 7. 交付物清单

| 交付物 | 位置 |
|---|---|
| 方案与迭代计划 | [DESIGN.md](DESIGN.md)、[ITERATION_PLAN.md](ITERATION_PLAN.md) |
| 审计报告与修复记录 | [AUDIT_REPORT.md](AUDIT_REPORT.md) |
| 生产部署文档 | [ADMIN_DEPLOYMENT.md](ADMIN_DEPLOYMENT.md) |
| 项目 README（含修改记录/致谢） | [README.md](README.md) |
| 源码 | `cmd/`、`server/`、`web/`、`nps-admin/` |
| 运维资产 | `docker-compose.yml`、`deploy/` |
| 压测工具 | `scripts/loadtest/` |
| SBOM | `sbom/xnps.cdx.json` |
| 发布包 | GitHub Release `v0.26.10-ext0.3.0` |

---

## 8. 已知限制与风险

| # | 项 | 说明 | 缓解/计划 |
|---|---|---|---|
| 1 | I3.1 待合并 | 代码在 `trae/agent-p7zySo`，master 尚未包含 | 合并后重新跑 CI 并推送 |
| 2 | Compose/Grafana 未实跑 | 沙箱无 Docker；CI 已加 docker job 做构建校验 | 上线前在目标环境执行 compose smoke |
| 3 | 真实支付未联调 | 支付宝/微信驱动与验签已单测，mock 全链路通过 | 上线前用沙箱商户号完成回调联调 |
| 4 | 退款/发票/对账报表缺失 | 仅充值/账单支付 | I4-A 计费闭环 |
| 5 | 配额惩罚不可恢复 | throttle/disable 后新账期未恢复 | I4-A 配额生命周期 |
| 6 | certd/builtin 未做真实 DNS-01/续期演练 | 内置 ACME 需公网 80 端口；certd 依赖外部流水线 | I4-B 证书演练 |
| 7 | 证书部署历史被覆盖 | 每 server 仅一条 deployment | I4-B append-only + 回滚 |
| 8 | 无多服务器调度/HA | 默认第一台 active 服务器 | I4-C 容量/健康选址 |
| 9 | 注册开放、无邮箱验证 | 可批量注册 | I4-C 验证码/邮箱验证 |
| 10 | `/ext/list` 每页全量排序 | 10 万 client 对账为 O(N log N)/页 | I4-B 游标优化 |
| 11 | md5 签名可重放 | nps 既有设计（±20s 无 nonce） | HTTPS + 防火墙白名单 |

---

## 9. 后续迭代建议（I4）

- **I4-A 计费与套餐闭环**：订阅分配/升降级折算、配额恢复、发票、退款、渠道对账、欠费宽限与催缴。
- **I4-B 证书与升级可靠性**：部署历史/回滚、certd 异步状态机、真实 ACME 演练、升级观察复检与制品口径统一。
- **I4-C 产品化与工程化**：告警 UI 操作、订阅/发票 UI、门户验证、多服务器调度、OpenAPI/i18n、Seeder、CI 全链路。

详见 [ITERATION_PLAN.md](ITERATION_PLAN.md)。

---

## 10. 验收结论

- 功能：内核扩展、管理端、UI、支付（mock/验签）、证书、升级、监控告警、健康检查与指标均可用。
- 质量：PHP/Go 测试、安全扫描、SBOM、CI 门禁已建立；真实 nps 宕机告警/恢复演练通过。
- 运维：生产部署文档、Compose、systemd、备份恢复、Grafana、压测工具齐备。
- 发布：GitHub Release 资产完整（30 个，145.9 MB，含 sha256）。
- 遗留：I3.1 合并、真实支付/ACME 联调、计费闭环与产品化 UI，列为 I4。

**结论：达到对外试运行标准；生产上线前需完成 I3.1 合并与 I4-A 计费闭环。**

---

## 附录：版本、提交与致谢

- 内核版本：`0.26.10`；扩展版本：`ext 0.3.0`；管理端标识：`nps-admin 0.4.0`。
- 关键提交：I0 `2d3522b`、I1 `7d915ed`、I2 `6cc9c57`、I3 `75804d1`；I3.1 待合并。
- 上游项目：ehang-io/nps（GPL-3.0）。本项目保留原许可证与版权声明，修改记录完整披露于 README；向原作者与社区致谢。
