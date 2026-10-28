# xnps 迭代方案（基于全量只读代码复核）

> **实施状态（2026-10-28）**：已交付管理控制台（Vue SPA，10 页）、支付宝/微信支付（含 mock 与回调验签）、certd + 内置 ACME 双证书来源；**I0 已完成**：P0-1（调度增殖）改为节拍派发 + `ShouldBeUnique`，P0-2（升级 Run 重复）、P0-3（版本签名口径）、P1-1（写操作重试）均已修复。**I1 已完成**：P1-2 证书自动续期、P1-5 升级流式下载与架构校验、P2-1/P2-2 流量与在线批量化、P2-3 端口池候选查询、P2-4 可达性写节流、P2-6 对账空集清理、L9 审批条件更新。**I2 已完成**：H3 升级下载 SSRF 防护、M1 默认校验证书 + require_https、M3 制品版本白名单、L1 token 过期与流式下载、P2-5 证书路径限制、P2-8 限流/abilities/密码策略、审计请求与响应脱敏；供应链升级 Go 1.26 + x/net 0.62 + xz 0.5.15，`govulncheck` 调用路径 0 漏洞（仅剩 x/crypto 1 项无修复公告且不可达），SBOM 落盘 `sbom/xnps.cdx.json`，安全与测试流水线固化于 `.github/workflows/ci.yml`。支付退款/对账报表仍待办。

- 复核基线：`master` @ `2e5b832`（含 ext 0.2.0 与全部审计修复）
- 方式：**只读**通读 Go 内核（`cmd/`、`server/ext/`、`lib/changelog/`、`web/controllers/`）与 Laravel 管理端（Services / Jobs / Controllers / Models / Policies / migrations / routes / config / CI），未修改任何代码
- 配套文档：[AUDIT_REPORT.md](AUDIT_REPORT.md)（已完成修复清单）、[DESIGN.md](DESIGN.md)（设计基线）、[DELIVERY.md](DELIVERY.md)（验收数据）

---

## 一、整体判断

| 维度 | 评价 |
|---|---|
| 架构 | 优秀。内核纯增量扩展（`/ext/*` 10 端点）+ 管理端三层同步，对上游侵入极小（13 文件 +1338 行，仅 2 处语义补丁），可维护性好 |
| 数据一致性 | 良好。唯一约束、Saga 补偿、三层幂等、对账兜底齐备；计费基线在 ext 0.2.0 已加行锁 |
| 安全 | 中。C1/H1/H2/H3 中 C1/H1/H2 已修；H3（SSRF）、M1（TLS 默认关）、M3（制品路径）、L1（token 无过期）仍在 |
| 可靠性 | **存在 3 个需要优先处理的问题**（见下节 P0）：任务自循环增殖、异步升级记录重复、升级回滚预检与版本口径冲突 |
| 工程质量 | 偏弱。管理端仅 3 个示例测试；CI 仍是上游 Go 1.15 工作流（与现有依赖不兼容）；无 Docker/可观测性/备份方案 |
| 产品完整度 | 骨架完整（门户/审批/计费/证书/升级），但缺运营闭环（支付、发票、通知、自动续证、管理 UI） |

---

## 二、本次复核新发现（按优先级）

### P0 — 建议作为 hotfix 立即处理

#### P0-1. 自循环 Job 每 10 分钟增殖一次，长期运行 API 负载线性增长

- 位置：[console.php](file:///workspace/nps-admin/routes/console.php#L15-L21)（`everyTenMinutes` 无条件派发种子）与 [console.php](file:///workspace/nps-admin/routes/console.php#L49-L54)（metrics 种子）；自循环实现见 [SyncVersionJob.php](file:///workspace/nps-admin/app/Jobs/SyncVersionJob.php#L44-L48)、[SyncOnlineJob.php](file:///workspace/nps-admin/app/Jobs/SyncOnlineJob.php#L39-L43)、[SyncFlowJob.php](file:///workspace/nps-admin/app/Jobs/SyncFlowJob.php#L58-L62)、[CollectMetricsJob.php](file:///workspace/nps-admin/app/Jobs/CollectMetricsJob.php#L34-L38)
- 机制：每个 Job 完成后 `static::dispatch()->delay(interval)` 形成永久循环；调度器每 10 分钟再派发一颗"新种子"，该种子同样自循环，且无 `ShouldBeUnique`/标记去重 → 每 10 分钟每个服务器每类任务多一条永久循环。24 小时后单服务器约 144 条 `/ext/version` 循环（10s 一次）→ 约 14 次/秒无效请求/服务器，50–100 台规模会直接打满 nps WebAPI 与 Laravel 队列。
- 影响：可用性 + 成本；且与 M2 的防重叠锁叠加后表现为"随机跳过采样"。
- 建议：**废弃自循环**，改为调度器节拍 + 一次性 Job：`Schedule::call(...)->everyTenSeconds()->withoutOverlapping()`（online 30s、flow 5min、metrics 30s），Job 内不再 `dispatch` 自身；种子逻辑删除。若必须保留自循环，则用"循环标记 + 幂等种子"（`Cache::add("nps-loop:{type}:{server}", ...)` 原子抢占），不能用 `ShouldBeUnique`（会在 handle 内自派发时被自身锁丢弃）。
- 验收：连续运行 24h，队列中每服务器每类任务恒为 0–1 条待执行；API 请求速率恒定；`queue:failed` 不增长。

#### P0-2. 异步升级会创建两条 Run，原 Run 永久 `running`

- 位置：[UpgradeController.php](file:///workspace/nps-admin/app/Http/Controllers/Admin/UpgradeController.php#L93-L106)（异步分支先建 `UpgradeRun` + `UpgradeStep` 再派发 Job）↔ [UpgradeOrchestrator.php](file:///workspace/nps-admin/app/Services/Upgrade/UpgradeOrchestrator.php#L45-L67)（`start()` 内部又 `UpgradeRun::create` + 全量 `UpgradeStep::create`）
- 机制：默认（异步）路径产生 Run A（控制器创建，状态 running，永不更新）+ Run B（编排器创建，实际执行）。接口返回 A，用户看到的进度永远停在 running；`runs()` 列表出现重复记录；B 的 steps 与 A 的 steps 重复。
- 建议：二选一——① 编排器改为 `start(UpgradeRun $run)` 复用已有 Run/Steps；② 控制器只做校验与派发，Run/Steps 全部由编排器创建并回填 Job。并补 Run 状态收敛（异常/超时 → failed，含 `finished_at`、`summary.error`）。
- 验收：异步升级后 `upgrade_runs` 仅 1 条记录，状态按 succeeded/failed/aborted 收敛；steps 数与 server_ids 一致；Job 超时/进程重启后可恢复为终态。

#### P0-3. M6 回滚预检与版本签名口径冲突 → 当前所有升级都会被 precheck 拒绝

- 位置：[UpgradeOrchestrator.php](file:///workspace/nps-admin/app/Services/Upgrade/UpgradeOrchestrator.php#L242-L248)（用 `$currentVersion` 查制品）与 [UpgradeOrchestrator.php](file:///workspace/nps-admin/app/Services/Upgrade/UpgradeOrchestrator.php#L268-L289)（健康校验用 `"{version}-ext{ext_version}"` 签名）
- 机制：`/ext/version` 的 `version` 是内核版本 `0.26.10`，而制品 `version` 按发布约定是 `0.26.10-ext0.2.0`，两者永不相等；`waitHealthy` 又要求制品 version 必须等于签名。即"上传签名格式 → 预检查不到旧制品；上传裸内核版本 → 健康校验永远失败"。
- 建议：统一版本口径——制品新增 `version_signature`（唯一）与 `core_version` 字段；预检按 `sprintf('%s-ext%s', core, ext)` 查找，裸内核版本作为兼容兜底；上传接口强制格式校验（同时解决 M3 路径字符问题）。
- 验收：上传 `0.26.10-ext0.2.0` 制品后，从同版本升级/回滚均能通过预检；不同 ext 版本回滚（0.2.0 → 0.1.0）可定位旧制品。

#### P1 — 紧随其后（可与 I1 合并）

| # | 问题 | 位置 | 建议 |
|---|---|---|---|
| P1-1 | 非幂等 POST 在连接错误/超时时重试，可能重复创建 client/tunnel | [NpsApiClient.php](file:///workspace/nps-admin/app/Services/Nps/NpsApiClient.php#L217-L229) | 仅对 GET/`/ext/*` 读端点重试；写操作不重试，交由 Saga 补偿；另修正注释所称"5xx 重试"实际未生效 |
| P1-2 | 证书只告警不续期（DESIGN §7.2 承诺自动续） | [CertificateService.php](file:///workspace/nps-admin/app/Services/Cert/CertificateService.php#L227-L234)、[MonitoringService.php](file:///workspace/nps-admin/app/Services/Monitor/MonitoringService.php#L138-L150) | 新增 `RenewCertificatesJob`（每日）：ACME 来源自动签发→部署→指纹核验；manual 来源仅告警并生成待办 |
| P1-3 | 管理端无法自助引导：无 admin 用户/套餐种子，注册用户恒为非管理员 | [DatabaseSeeder.php](file:///workspace/nps-admin/database/seeders/DatabaseSeeder.php#L16-L22)、[AuthController.php](file:///workspace/nps-admin/app/Http/Controllers/Portal/AuthController.php#L29-L44) | 增加 `admin:create` 命令 + `PlanSeeder` + 首个 admin 引导流程；文档同步 |
| P1-4 | 审计遗留：H3 SSRF、M1 TLS 默认关闭、M3 制品路径、L1 下载 token 无过期 | 见 [AUDIT_REPORT.md](AUDIT_REPORT.md) | 在 I2 统一收敛（详见路线） |
| P1-5 | 升级下载整读进内存（url 模式上限 256MB），且不校验二进制格式/架构 | [upgrade.go](file:///workspace/server/ext/upgrade.go#L132-L143) | 流式写临时文件 + 边写边 hash；校验 ELF/PE/Mach-O 头与 `GOARCH` 匹配（由 Laravel 传目标 arch） |
| P1-6 | 测试与 CI 形同虚设：管理端仅 3 个示例测试；Go 仅 changelog 有测试；`release.yml` 用 Go 1.15 + 旧命名资产，与 `build-xnps.sh` 冲突 | [tests/](file:///workspace/nps-admin/tests)、[release.yml](file:///workspace/.github/workflows/release.yml#L18) | 重建 CI：Go vet/test/race + 交叉编译矩阵，PHP lint/PHPUnit，发布改用 `build-xnps.sh` 并上传 sha256 |

### P2 — 性能与实现细节（I1/I3 择机）

| # | 问题 | 位置 | 建议 |
|---|---|---|---|
| P2-1 | `syncFlow` 每行 2 次查询；计费每行一个事务（含 `lockForUpdate`） | [SyncEngine.php](file:///workspace/nps-admin/app/Services/Sync/SyncEngine.php#L179-L222)、[BillingService.php](file:///workspace/nps-admin/app/Services/Portal/BillingService.php#L54-L111) | 按 client 批量 upsert；基线按分片批量锁/批量更新（或单事务包多行） |
| P2-2 | `syncOnline` 每 30s 全量分块读镜像 | [SyncEngine.php](file:///workspace/nps-admin/app/Services/Sync/SyncEngine.php#L140-L177) | 只对"在线集合 ∪ 本地在线集合"做差集更新；或改增量事件 |
| P2-3 | 端口自动分配线性探测（最坏 5.5 万次 DB 插入异常） | [ProvisioningService.php](file:///workspace/nps-admin/app/Services/Provisioning/ProvisioningService.php#L230-L249) | 一次查询已占用端口集合 + 区间取差；保留唯一约束兜底 |
| P2-4 | 每次 API 调用都 `saveQuietly` 写 `is_reachable` | [NpsApiClient.php](file:///workspace/nps-admin/app/Services/Nps/NpsApiClient.php#L232) | 变化时才写 / 内存节流（如 30s 一次） |
| P2-5 | 部署证书会隐式写 `web_open_ssl=true`；部署记录按 server 覆盖、无历史 | [cert.go](file:///workspace/server/ext/cert.go#L74-L82)、[CertificateService.php](file:///workspace/nps-admin/app/Services/Cert/CertificateService.php#L126-L134) | 明确"部署"与"启用 HTTPS"语义分离；增加部署历史表（server+cert+time） |
| P2-6 | 升级时对账空结果不执行删除（远端清空后镜像残留） | [SyncEngine.php](file:///workspace/nps-admin/app/Services/Sync/SyncEngine.php#L277-L281) | 远端 total=0 且请求成功时执行按 server 全删（加保护开关） |
| P2-7 | `absolutePath()` 会重复 `artifacts/` 前缀（当前未被使用，属陷阱） | [UpgradeArtifact.php](file:///workspace/nps-admin/app/Models/UpgradeArtifact.php#L31-L34) | 删除或改为 `Storage::path($filename)` |
| P2-8 | 管理端 API 无限流；`.env.example` 默认 `APP_DEBUG=true`；portal token ability `['*']` | [routes/api.php](file:///workspace/nps-admin/routes/api.php#L36-L38)、[.env.example](file:///workspace/nps-admin/.env.example#L4)、[AuthController.php](file:///workspace/nps-admin/app/Http/Controllers/Portal/AuthController.php#L53) | admin 路由加 `throttle`；生产 env 模板 `APP_DEBUG=false`；token abilities 收敛为 `portal` |

**已确认良好、不建议改动的部分**：Go 侧鉴权链与 `/ext/*` 权限、Saga 补偿、三层幂等、唯一约束设计、`/ext/list` 游标语义、changelog 环形结构与回绕检测、`reconcile` 的游标回写、计费"重启清零"处理、Laravel Policy 归属校验。

---

## 三、迭代路线

> 按"先止血 → 再加固 → 再观测 → 再产品化"排序；每个迭代可独立发布，ext 与管理端版本各自递增。

### I0 热修复（ext 0.2.1 / admin 0.2.1）

**目标**：消除 3 个 P0，让现网升级与长时间运行安全。

范围：
1. P0-3 版本口径统一（制品 `version_signature`/`core_version` + 预检查找 + 上传格式校验）。
2. P0-2 升级 Run 复用与状态收敛。
3. P0-1 调度重构（去掉自循环，改调度节拍 + `withoutOverlapping`）。
4. P1-1 写操作重试策略修正。
5. 为以上三项补回归测试（升级 Run 唯一性、调度 24h 模拟、版本签名匹配）。

出口标准：异步升级只产生 1 条 Run；从 `0.26.10-ext0.2.0` 升级到 0.2.1 并能回滚；模拟 24h 调度后任务数恒定；`go test ./...`（受影响包）+ PHP 单测通过。

### I1 稳定性与数据一致性（ext 0.3.0 / admin 0.3.0）

**目标**：长时间运行、故障恢复、计费准确。

范围：
1. P1-2 证书自动续期 Job（含 ACME 失败重试与人工兜底）。
2. P1-5 升级流式下载 + 架构校验 + Windows 路径明确化（`-update` 文件 + 手动替换提示 + 健康检查超时）。
3. P2-1/P2-2 流量与在线同步批量化（目标：16 分片采集在 1000 client/片下 < 2s，DB 写放大降一个数量级）。
4. P2-3 端口池候选集合查询。
5. P2-6 对账空集删除（带保护开关）+ 并发写入窗口的边界测试。
6. L9 审批状态条件更新（`where status=submitted` + 影响行数校验）、P2-4 `is_reachable` 节流。
7. 引入故障注入测试：队列重启、重复调度、nps 重启（changelog 回退）、升级中断。

出口标准：故障注入全部通过；同一变更重复采样不重复计费；对账 30 万行 ≤ 现有 7m32s 且结果幂等（连跑两次行数一致）；升级中断后 Run 状态可收敛、可回滚。

### I2 安全加固（ext 0.3.x / admin 0.3.x）

**目标**：清空审计 High/Medium 安全项，达到可对外试运行标准。

范围：
1. H3：`/ext/upgrade` 仅允许 `https://`、禁重定向、可选目标 host 白名单（等于集中端地址）。
2. M1：`tls_verify` 默认 true（自签需显式关闭并告警）；携带私钥/配置的调用在明文 HTTP 下拒绝或强制告警。
3. M3：制品 `version` 正则白名单（`^[A-Za-z0-9._-]+$`），与 I0 的 `version_signature` 合并校验。
4. L1：下载 token 加 `expires_at` + 一次性轮换；下载改流式（避免整读）。
5. P2-5：`/ext/cert` 只允许写 `conf/` 目录内路径（防配置项指向任意路径）。
6. P2-8：admin 路由 `throttle`、登录失败锁定、密码强度、token abilities 收敛。
7. 审计响应体脱敏（`verify_key`、`cert_pem` 等），保留天数与导出权限；`/ext/config` GET 对未知键也做敏感词脱敏。
8. 供应链：Go 1.22+（更新 `go.mod`）、`govulncheck`、`composer audit`、SBOM、发布包 sha256 与签名（cosign 可选）。

出口标准：未授权/越权/SSRF/路径穿越用例全部拦截并留痕；依赖扫描无 High/Critical；`APP_DEBUG=false` 模板；安全测试用例进 CI。

### I3 可观测性与运维（admin 0.4.0）

**目标**：可运维、可告警、可回滚。

范围：
1. 管理端 `/healthz`（DB/队列/缓存）与 `/metrics`（Prometheus：同步延迟、队列积压、API 错误率、计费耗时、升级状态）。
2. Go `/ext/system` 扩展（磁盘、fd、goroutine 峰值）——仍走签名鉴权，不新增公开端口。
3. 结构化日志（JSON + request_id）、慢查询、同步链路 trace 字段。
4. 告警：webhook 重试/退避、静默/确认、去抖窗口可配置；告警列表 API。
5. Dockerfile（xnps / nps-admin）+ docker-compose 开发栈；systemd 单元模板；备份/恢复脚本（DB + 制品库 + conf 快照）。
6. CI 重建（替换 `release.yml`）：构建矩阵 + 测试 + 发布 `build-xnps.sh` 产物 + 校验和；保留 make 入口。
7. 压测脚本入库（当前仓库无 10 万级压测代码，仅 DELIVERY 记录结果），支持回归基线对比。

出口标准：单机 compose 一键起（xnps + admin + queue + DB）；Grafana 面板覆盖同步/计费/升级三条主链路；模拟 nps 宕机 5 分钟内产生告警并可恢复；发布流水线全自动且资产命名与 `build-xnps.sh` 一致。

### I4 产品化（admin 0.5+ / ext 0.4）

**目标**：从"可用"到"可运营"。

范围（按决策结果裁剪）：
1. **管理端 UI**（需决策）：API-only 还是提供管理控制台（服务器/同步状态、client/隧道、审批、账单、证书、升级、告警）。若做，建议最小可用 8 屏。
2. 计费闭环：套餐变更/升降级与按比例折算、发票、支付渠道（Stripe/支付宝/微信按市场选型）、退款、欠费宽限期与催缴、账单对账报表。
3. 审批体验：邮件/webhook 通知、批量审批、SLA 超时提醒、审批留痕导出。
4. 多服务器调度：按健康度/容量/端口余量选址；跨服务器 vkey 唯一性；迁移能力。
5. ACME/certd 真实集成与续期演练（当前 `AcmeProvider` 为骨架、`certd` 未实测）；证书部署历史与回滚。
6. API 文档（OpenAPI）+ 客户端示例；i18n；管理端错误码规范。

出口标准：用户"注册→申请→审批→开通→计费→续费/欠费→停用"全生命周期 E2E；一次真实 ACME 签发 + 自动续期 + 滚动部署演练；灰度升级含失败回滚演练。

### 持续工作（每迭代并行）

- 测试：Go 侧 `/ext/*` handler 测试（含签名失败、越界参数、超大 body）+ `-race`；PHP 侧 Feature 测试（C1 鉴权、Policy 越权、Saga 补偿、计费幂等、同步应用）。
- 静态检查：`golangci-lint`、Larastan/PHPStan、Pint；PR 门禁。
- 文档：运维手册（部署/升级/回滚/证书/备份）、API 文档、故障排查 Runbook；README 修改记录随版本更新。
- 发布：ext 版本递增规则（行为变更 minor、修复 patch）、制品库保留 N-2 版本、Release Notes 模板。

---

## 四、版本与兼容策略

- 版本签名：`{core}-ext{ext}`（如 `0.26.10-ext0.3.0`）；制品库同时存 `core_version`、`ext_version`、`version_signature`。
- 兼容矩阵：`core_version` 不变才允许升级（现有预检保留）；npc 握手以 `0.26.0` 为最低兼容。
- 回滚：制品库保留当前 + 上一版本；nps 本地 `.bak-ext` 双份兜底；Laravel 预检"旧制品必须在库"保留但改用签名查找。
- 发布资产：server/client 双包 × 14 平台 + `sha256sums.txt`，由 `build-xnps.sh` 生成；CI 与手工发布使用同一脚本。

---

## 五、建议优先级汇总

| 顺序 | 事项 | 类别 | 依赖 |
|---|---|---|---|
| 1 | P0-3 版本口径 / P0-2 升级 Run / P0-1 调度增殖 | 修正 | 无 |
| 2 | P1-1 写重试 / P1-6 CI 与测试骨架 | 可靠性/工程 | 无 |
| 3 | P1-2 自动续证 / P1-5 升级流式与架构校验 | 功能/可靠性 | I0 |
| 4 | P2-1/2/3 同步与端口性能 | 性能 | I1 |
| 5 | H3/M1/M3/L1 + 管理端限流与引导 | 安全 | I1 |
| 6 | 可观测性 + Docker/备份 + 发布流水线 | 运维 | I2 |
| 7 | 管理 UI / 支付发票 / 多服务器调度 / ACME 实战 | 产品 | 决策 |

## 六、需要先决策的问题

1. 管理端是否提供 UI（决定 I4 体量）？
2. 支付渠道与发票合规要求（决定计费模型落地方式）？
3. 证书来源以 certd 为主还是内置 ACME（决定续期 Job 的实现路径）？
4. 是否要求多租户强隔离（独立库/独立部署）与 SLA 指标？
5. 升级策略：是否允许跨 ext 版本回滚、制品保留数量、维护窗口？

## 七、复核范围说明

- 已覆盖：Go 内核全部新增/侵入文件、鉴权链、changelog、ext 端点；Laravel 全部 Service/Job/Controller/Model/Policy/中间件/路由/迁移/配置/CI/种子与测试目录。
- 未覆盖：动态渗透、真实 ACME/certd、多机升级演练、Windows 实机、压测复跑（沿用 DELIVERY 数据）、`composer.lock` 锁定版本核对。
