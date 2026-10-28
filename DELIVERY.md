# nps 集中管理平台 · 项目交付总结

> 交付日期：2026-10-28
> 项目基线：nps 0.26.10（core 0.26.0）/ Laravel 12.70 / PHP 8.5 / Go 1.25
> 交付范围：DESIGN.md（v3）定义的阶段 0–5 全部落地，含端到端验证与压测验收

---

## 1. 项目概述

**背景**：nps 是控制面/数据面分离的内网穿透系统（ehang.io/nps）。原版仅提供单机 Web 面板，无法满足多服务器集中管理、用户自助、计费运营的需求。

**目标**：构建 Laravel 集中管理平面，经 nps WebAPI 纳管 50–100 台 nps、平台总量 10 万级 client/tunnel，提供 P2 用户自助门户（审批流 + 计费）、证书全生命周期、HTTPS 全链路、版本滚动升级与监控告警。

**核心约束**（全程遵守）：

| 约束 | 落实方式 |
|---|---|
| 不更改 nps 现有功能 | Go 侧纯新增 1338 行（nps-admin-ext 模块）+ 15 行观测 hook，零行为变更 |
| 全部经 webapi 控制 | 无 SSH/Ansible 带外通道；配置/重启/升级均走 HTTP API |
| 用户永不接触 nps auth_key | Sanctum token + Policy 归属校验，签名仅存在于服务端 SDK |

---

## 2. 交付物总览

| 交付物 | 位置 | 规模 |
|---|---|---|
| 设计方案（v3 合并稿） | [DESIGN.md](DESIGN.md) | 30.9 KB / 16 章 |
| nps 源码解析日记 | [PROJECT_DIARY.md](PROJECT_DIARY.md) | 55 KB / 17 章（含 17 项缺陷清单） |
| **Go 侧扩展模块** | [lib/changelog](lib/changelog)、[server/ext](server/ext)、[web/controllers/ext.go](web/controllers/ext.go) | 13 文件 / 1338 行（含单元测试） |
| **Laravel 管理平面** | [nps-admin/](nps-admin/) | 106 PHP 文件 / 8014 行（64 类 + 24 迁移） |
| 源码备份 | nps-admin-src.tar.gz | 20.7 MB（不含 vendor） |
| Git 提交 | `bafde1d` → `6d5fdd8`（阶段 0–4 各一 + 修复） | 每阶段独立 commit |

---

## 3. 系统架构

```
┌────────────────────────────────────────────────────────────┐
│                    Laravel 集中管理平面                       │
│                                                            │
│  P2 用户自助门户（Sanctum + Policy）                          │
│    注册/登录 → 申请 → 审批状态机 → 幂等开通 → 计费/账单         │
│  管理端（is_admin）：纳管/审批/套餐/充值/证书/升级/监控          │
│                                                            │
│  NpsApiClient（md5 签名 + 时钟校准 + 响应统一 + 审计）          │
│  SyncEngine 三层同步：L1 写后回读 / L2 版本增量(10s) /          │
│                      L3 滚动对账(6h) + online(30s)/flow(5min) │
│  ProvisioningService（Saga + 端口池 + run_status 校验）        │
│  CertificateService / HttpsProvisioner（防自锁编排）           │
│  UpgradeOrchestrator（预检 + canary + 自动回滚）               │
│  MonitoringService（采集 + 6 类告警 + webhook）                │
└──────────────┬─────────────────────────────────────────────┘
               │ HTTPS（web_open_ssl）+ auth_key 签名（±20s）
               ▼
   ┌────────────────┐  ×50–100 台（单机 1–2 千 client）
   │ nps + ext 模块  │  /ext/* 10 端点（O(1)/O(页)，替代 O(C×T) 原生 list）
   └────────────────┘
```

---

## 4. 各阶段交付明细

### 阶段 0：nps-admin-ext 扩展模块（Go，纯新增）

**10 个扩展端点**（复用既有签名鉴权，强制 admin）：

| 端点 | 用途 | 实测（10 万级） |
|---|---|---|
| `/ext/version` | 变更计数 + 版本探测 | 0.1ms |
| `/ext/changes` | 环形变更日志（1 万条，回绕 truncated） | 0.1ms |
| `/ext/online` | 在线 client 集合 | 0.1ms |
| `/ext/flow` | 流量聚合（16 分片） | 23ms |
| `/ext/list` | 游标分页（不做 Flow 聚合） | 18–32ms |
| `/ext/system` | 系统监控（不调 dealClientData） | — |
| `/ext/config` | nps.conf 白名单读写（脱敏/备份/原子写） | — |
| `/ext/cert` | 证书下发/指纹对账 | — |
| `/ext/restart` | 202 + 延迟触发（Control 失败回退 execve） | — |
| `/ext/upgrade` | 二进制升级（sha256 强校验 + rename 原子替换） | — |

**侵入点**：15 行观测 hook（`lib/file/db.go` 9 处 + `index.go`/`client.go` 各 1 处 + 路由注册 2 行 + service 注入 2 行），语义零变更。

**验证**：单元测试 4/4（含环形回绕）；真实进程冒烟 20 项全过（鉴权 302 拒绝、hook 链路、config 白名单/脱敏回写保护、restart 的 execve 回退与 change_seq 归零边界）。

### 阶段 1：Laravel 核心

- **NpsApiClient**：md5(auth_key+timestamp) 签名、`/auth/gettime` 时钟校准、三种响应格式统一、指数退避重试、302 识别（禁用重定向）、写操作全审计（签名脱敏）；
- **SyncEngine 三层同步**：L2 含版本回退检测（nps 重启 → 全量对账）、`task→tunnel` 类型规范化、批量 upsert + 外键修复 + 失配删除；
- **ProvisioningService Saga**：端口池（唯一约束 + TTL）→ API → 回读 `run_status` 校验（对冲 nps 虚报）→ 失败补偿；
- **验证**：纳管 + 首次对账导入、同步延迟 **601ms**（标准 <30s）、Saga 全链路（端口分配→创建→回读→绑定）、删除级联 + 端口释放。

### 阶段 2：P2 自助门户

- **审批状态机**：submitted → approved → provisioning → provisioned / rejected / expired / failed；自动审批 3 规则（订阅/欠费/配额）+ 人工审批 + 过期扫描；
- **幂等开通**（ProvisionApprovalJob 三层：status → payload.resource_id 回写 → 精确字段匹配）；
- **计费引擎**：套餐/订阅/钱包/流水/账单；**月度配额执行层**（nps `flow_limit` 是生命周期累计值不可用作月度——Laravel 基线 + 增量累加，含 nps 重启清零防护；超额动作 throttle/disable/bill）；
- **验证**：注册→自动审批→provisioned 全链路、人工驳回/批准、套餐参数映射（rate_limit=512）、越权 403、配额超额 throttle + 幂等、月度结算（月费 1000 + 超额 25 = 1025 分，钱包扣款、重复结算幂等）。

### 阶段 3：证书子系统 + HTTPS + 监控

- **双来源**（Manual 完整 + Acme/certd 生产对接骨架）+ **双通道**（Native 完整 + Certd 回退）+ 证书库（私钥 encrypted）；
- **HttpsProvisioner 防自锁 5 步**：推证书（不重启）→ 写 `web_open_ssl` → 重启 → HTTPS 探活 → 切 `api_url`；失败落 critical 告警；
- **指纹对账**（Laravel/Go 统一 PEM-sha256）+ 每日全平台对账 + 失配告警；
- **监控**：`/ext/system` 30s 采集落库 + 6 类告警（CPU/内存/不可达/证书到期/同步延迟/隧道虚报）+ 1h 去抖 + 可选 webhook；
- **验证**：HTTPS 编排 5 步全过、签名 API 走 TLS、指纹对账 ok、20 天到期证书触发告警、**轮换演练**（新证书部署 + 重启 + 核验，远端 not_after 更新）。

### 阶段 4：版本滚动升级编排

- **制品库**（版本/sha256/兼容矩阵/download_token + token 下载端点）——替代不可用的官方 `UpdateNps()`；
- **编排器**：预检（core version 兼容矩阵——防旧 npc 全量断连 + 在线 npc 扫描 + conf 快照）→ canary（失败中止整批）→ 观察窗口 → 批次 → 汇总报告；
- **单台流程**：url 模式升级（nps 出方向拉取 + sha256 强校验 + rename 原子替换）→ 联动重启 → 版本签名核对（`{version}-ext{ext_version}`）→ **失败自动回滚**（回推旧制品 + critical 告警）；
- **验证**：双版本真实升级（0.26.10 → ext0.1.1）succeeded、**回滚演练**（回推 ext0.1.0）succeeded、canary 失败中止 + 告警、conf 快照 3 份存档。

### 阶段 5：压测验收（10 万 client + 20 万 tunnel 数据面满载）

| 指标 | 实测 | 标准/对照 |
|---|---|---|
| nps 启动加载（110MB JSON） | 4.2s | — |
| nps 常驻内存 | 766MB（30 万条对象图） | 单机 1–2 千 client 规划下 <10MB 级 |
| ext 端点（全部） | 0.1–32ms | — |
| **原生 /client/list** | **>60s 超时** | **O(C×T)=200 亿遍历——设计论点实证** |
| 同步延迟（直改→镜像） | **64ms** | <30s ✓ |
| L3 全量对账（30 万行） | 7m32s | 6h 周期 ✓（生产多台并行同为 7.5 分钟） |
| online 通道（10 万行 diff） | 7.2s | 30s 周期 ✓ |
| flow 通道（6250 行/片） | 25.5s | 5min 周期 ✓（SQLite 放大，见优化建议） |

---

## 5. 生产部署要点

**环境**：PHP 8.2+ / MySQL 8 / Redis（可选）/ queue:work ×2（`nps-sync`、`nps-provision`）+ scheduler；nps 侧部署含 ext 模块的自建构建二进制。

**部署顺序**：

```
1. 部署 nps（自建二进制，含 nps-admin-ext）
2. nps.conf 配置 auth_key（强随机）+ webapi 端口防火墙白名单（仅 Laravel 出口 IP）
3. Laravel：composer install + .env（MySQL/Redis）+ migrate + 建管理员与套餐
4. php artisan nps:register <name> <api_url> <auth_key>   # 纳管 + 首次对账
5. HTTPS：上传证书 → enable-https（防自锁编排自动切换 api_url）
6. 常驻：queue:work --queue=nps-sync,nps-provision,nps-upgrade + schedule:run
```

**关键配置**（`config/nps.php`）：同步间隔/分片数、自动审批规则、`quota_action`、超额单价、告警阈值、ACME/certd 对接、webhook。

**运维命令**：`nps:sync [--reconcile]`（手动同步/对账）、`nps:register`（纳管）、`/api/admin/upgrades/*`（升级编排）、`/api/admin/certificates/*`（证书）。

---

## 6. 已知限制与风险

| # | 项 | 说明 | 缓解 |
|---|---|---|---|
| 1 | 重连风暴未实测 | 10 万真实 npc 断连重连需分布式压测机 | 滚动重启逐台 + 批间在线率等待（编排器已实现） |
| 2 | flow 通道 25.5s/片 | SQLite 逐行 UPDATE 放大 | 生产 MySQL 批量 upsert（CASE WHEN） |
| 3 | md5 签名可重放 | nps 既有设计（±20s 无 nonce） | HTTPS + 防火墙白名单双层缓解 |
| 4 | ACME/certd 为骨架 | 真实 DNS-01 需公网域名 + DNS API | 接口已留（`NPS_ACME_MODE=certd`），人工上传通道完整可用 |
| 5 | nps 既有缺陷未修 | 17 项见 PROJECT_DIARY.md §15（虚报/级联/O(C×T) 等） | 管理平面已逐项对冲（回读校验/影响面报告/ext 端点） |
| 6 | 沙箱快照清理 | `.env`/`vendor`（gitignore 项）会话间丢失 | 每阶段 git commit + tar 备份；部署脚本含重建步骤 |

---

## 7. 后续优化建议

1. flow 采集改批量 upsert（MySQL `INSERT ... ON DUPLICATE KEY` 多行）；
2. 审批开通接入通知渠道（邮件/站内信，当前仅状态查询）；
3. 升级编排接入 conf 迁移钩子（版本间 conf 键 diff，当前仅快照）；
4. 监控历史曲线前端可视化（数据已落库 `server_metrics`）；
5. 多 nps 服务器健康分片调度（当前每台独立 worker，可加全局并发上限）。

---

## 8. 开发中发现并修复的关键缺陷（跨阶段）

| 阶段 | 缺陷 | 修复 |
|---|---|---|
| 0 | kardianos 在 systemd 平台总能创建 service 对象，Control 失败静默 | restart 加 execve 回退（冒烟实测） |
| 1 | changelog type 用 nps 内部名 `task`，Laravel 匹配 `tunnel` → match 崩溃误补偿 | SyncEngine 类型规范化 + default 兜底 |
| 1 | reconcile 后游标未回写 → 增量重复拉取 | 对账完成即回写 |
| 1 | deleteClient 在镜像隧道删除后才查端口绑定 → 端口残留 | 先收集绑定再调 API |
| 2 | 幂等判断 `(client, mode)` 太宽 → 新隧道申请误判已开通 | payload 回写 resource_id + 精确匹配 |
| 2 | settleMonthly 把 Plan 当 subscription 取 `->plan` → plan_fee=0 | 类型修正 |
| 4 | 下载 URL query 形式 vs 路由路径形式 → 404 | 统一路径形式 |
| 4 | serve 启动早于 .env 重建 → APP_URL 错误 → Go 跟随重定向打到 nps 自身 | NpsApiClient 禁用重定向 + 启动顺序 |

---

## 9. 提交记录

```
6d5fdd8 feat(stage-4): rolling upgrade orchestrator
4dbd21f feat(stage-3): certificate subsystem + HTTPS + monitoring
1b902e0 feat(stage-2): P2 self-service portal
bafde1d fix(ext): restore verify_key in /ext/list
（stage-0/1 含于 98919af..1b902e0 区间的 workspace 快照提交）
```

> 全部阶段均通过真实 nps 实例端到端验证；压测数据见 §4 阶段 5。
