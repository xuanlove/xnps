# nps-admin（xnps + Laravel 管理端）代码审计报告

- 审计日期：2026-10-28
- 审计对象：`/workspace`（Go，ehang.io/nps 0.26.10 fork，HEAD `c8bff04`，新增代码以 `98919af..HEAD` 为准）与 `/workspace/nps-admin`（Laravel 12 / PHP 8.2+，约 106 个 PHP 文件 / 8000 行）
- 审计方式：**只读静态审计**（人工逐文件通读 + 关键路径交叉验证），未修改任何代码
- 审计人视角：安全（鉴权/越权/注入/SSRF/密钥处理）、正确性（并发/事务/幂等/契约）、健壮性（错误处理/资源/兼容）

---

## 一、总体结论

| 维度 | 评级 | 说明 |
|---|---|---|
| 架构与侵入面 | 良好 | Go 侧全部为纯新增（13 文件 +1338 行，diff 逐行核实无删改），侵入点仅 8 处 `changelog.Append` + 2 行路由 + 2 行 service 注入 |
| 管理面鉴权 | **不通过** | `/api/nps/*` 全部无鉴权，且 auth_key 可被未认证读取（C1，可链式接管全部 nps 服务器） |
| 敏感数据处理 | **不通过** | 私钥/敏感配置明文落审计日志（H1）；nps.conf 写回权限过宽（M5） |
| 业务正确性 | 中等 | nps 原生 `/client/edit` 部分更新语义 + Laravel 部分参数调用 = 配置清零（H2）；changelog 乱序丢事件（H4） |
| 幂等与事务 | 良好 | bills/flow_monthly/approvals 等唯一约束齐备，Saga 补偿、三层幂等设计到位；个别读改写非原子（M2） |
| 门户（P2）鉴权 | 良好 | Policy 归属校验正确， Sanctum + admin 中间件就位；缺限流（M7） |

**结论：代码结构与同步/编排设计质量较高，但存在 1 个 Critical 鉴权缺陷，上线前必须修复；修复 C1/H1/H2/H3/H4 五项后可进入受控试运行。**

---

## 二、问题清单（按严重级别）

### Critical

#### C1. 管理面 API 完全无鉴权 + auth_key 明文回显 → 可链式接管全部 nps 服务器

- 位置：
  - [routes/api.php](file:///workspace/nps-admin/routes/api.php#L71-L103)（`Route::prefix('nps')` 组无任何 auth 中间件，注释自认"生产应叠加 admin 中间件"）
  - [ServerController.php](file:///workspace/nps-admin/app/Http/Controllers/ServerController.php#L83-L86)（`show()` 直接返回完整模型）
  - [NpsServer.php](file:///workspace/nps-admin/app/Models/NpsServer.php#L13-L27)（`$fillable` 含 `auth_key`、encrypted cast 解密，但**未设置 `$hidden`**）
- 攻击链：
  1. 未认证 `GET /api/nps/servers` 枚举服务器，`GET /api/nps/servers/{id}` 直接拿到解密后的 `auth_key`；
  2. 用 `auth_key` 对目标 nps 构造 `md5(auth_key+timestamp)` 签名调用任意 webapi/ext 端点（[base.go#L36-L42](file:///workspace/web/controllers/base.go#L36-L42)：签名通过即 `SetSession("isAdmin", true)`）；
  3. 经 `/ext/config`、`/ext/upgrade`、`/ext/cert`、`/index/*` 完全控制每台 nps（改配置、替换二进制、换证书、增删隧道）。
- 影响：全部被纳管 nps 实例失陷（等同 RCE 级）。
- 修复建议：
  1. `/api/nps/*` 与 `/api/artifacts/*`（除 download token 端点外）统一叠加 `['auth:sanctum', 'admin']`；
  2. `NpsServer` 增加 `protected $hidden = ['auth_key'];`，所有响应绝不回显密钥；
  3. `nps-api-logs` 之外，考虑对 `NpsServer::show` 增加显式白名单字段输出。

### High

#### H1. 审计日志明文落盘私钥与敏感配置

- 位置：[NpsApiClient.php](file:///workspace/nps-admin/app/Services/Nps/NpsApiClient.php#L287-L314)（`audit()` 原样写入 `'json' => $json`）
- 触发路径：`NativeChannel::deploy()`（[NativeChannel.php](file:///workspace/nps-admin/app/Services/Cert/NativeChannel.php#L19-L28)）把 `key_pem_b64`（**私钥**）POST 给 `/ext/cert`；`extConfigSet` 可携带 `web_password`/`auth_key` 等敏感键 —— 均被完整写入 `nps_api_logs.request`，绕过 `Certificate.key_pem` 的 encrypted cast 防护（[Certificate.php](file:///workspace/nps-admin/app/Models/Certificate.php#L18-L25)）。
- 影响：数据库/备份/日志同步泄露即私钥泄露；`redact()` 目前只删 `auth_key/timestamp` 签名参数。
- 修复建议：审计前对 json 做键级脱敏（`key_pem_b64`、`cert_pem_b64`、`entries` 中的 sensitive 键替换为 `******`），或对 `/ext/cert`、`/ext/config` 两类端点按 endpoint 白名单脱敏。

#### H2. nps 原生 /client/edit 为"全量替换"语义，Laravel 侧多处仅传部分参数 → 用户 client 配置被清零

- 位置：
  - Go 根因：[client.go](file:///workspace/web/controllers/client.go#L121-L135)（`GetIntNoErr` 缺参返回 0，`u/p/web_password` 直接置空 —— 上游语义）
  - Laravel 调用点 1：[BillingService.php](file:///workspace/nps-admin/app/Services/Portal/BillingService.php#L310-L321)（`throttleUserClients` 只传 `rate_limit`）
  - Laravel 调用点 2：[ClientController.php](file:///workspace/nps-admin/app/Http/Controllers/ClientController.php#L100-L117)（`update` 只透传请求中出现的键）
- 后果：月度超额限流（`quota_action=throttle`，config 默认即 throttle）或任意一次部分字段编辑，会把该用户全部 client 的 `flow_limit/max_conn/max_tunnel` 清 0（变不限）、`u/p`（Basic 认证）与 `web_password`（nps 门户密码）清空、`compress/crypt` 置 false。
- 修复建议（二选一）：
  1. Laravel 侧封装 `editClientFull()`：先从镜像/回读补全全部字段再提交；
  2. Go 侧补丁：`EditClient` 改为"显式传入才修改"语义（需同步修改 upstream 行为，改动面更大但治本）。

#### H3. `/ext/upgrade` url 模式存在 SSRF（无 scheme/host 限制、跟随重定向）

- 位置：[upgrade.go](file:///workspace/server/ext/upgrade.go#L100-L116)（`download()` 直接 `client.Get(url)`，未限定 https、未禁止内网地址、默认跟随最多 10 次重定向）
- 影响：持有 auth_key/管理员会话者可令 nps 向任意内网地址发起 GET（云元数据 169.254.169.254、内网管理面等），错误信息回显 HTTP status 可作内网探测。
- 缓解现状：sha256 必填且强校验（响应体不匹配即丢弃），不能直接注入二进制。
- 修复建议：限定 `https://`；禁跟随重定向（`CheckRedirect` 返回 `http.ErrUseLastResponse`）；可选：目标 host 必须等于配置的集中管理端地址（Laravel 编排器本就只会传制品库 URL）。

#### H4. changelog 并发乱序写入可导致增量同步永久漏事件

- 位置：[changelog.go](file:///workspace/lib/changelog/changelog.go#L35-L44)（`Append` 先 `atomic.AddInt64` 取 seq，后抢锁写入环形缓冲 —— 两个并发 Append 的 seq 与写入顺序可能颠倒）
- 失效场景：T1 取 seq=5 未入队、T2 取 seq=6 先入队；此刻管理端读到 `Version()=6` 并拉 `Since(5)`（拿到 6，无 5）；下轮 `Since(6)` 也永远拿不到 5 → 该次 add/update/del 漏同步，镜像漂移直到 6 小时全量对账。
- 概率：低（需毫秒级并发写），但 10 万 client 高频变更下并非不可能。
- 修复建议：`Append` 改为锁内分配 seq（counter 一并交由 mutex 保护，或锁内再次 `atomic.AddInt64` 使 seq 分配与入队原子化），保证环形缓冲内 seq 单调递增。

### Medium

#### M1. 私钥可能经明文 HTTP / 不校验 TLS 通道推送

- 位置：[HttpsProvisioner.php](file:///workspace/nps-admin/app/Services/Cert/HttpsProvisioner.php#L36-L87)（`enable()` 默认 `tlsVerify=false`，`api_url` 切 https 前是 `http://`）；[NpsApiClient.php](file:///workspace/nps-admin/app/Services/Nps/NpsApiClient.php#L176-L182)（`verify` 按 `tls_verify` 可关闭）
- 说明：自签场景的工程权衡，但生产部署若长期停留 `http://` 或 `tls_verify=false`，`/ext/cert`、`auth_key` 签名可被中间人截获。
- 建议：生产 checklist 强制 CA 证书 + `tls_verify=true`；对携带私钥的调用（/ext/cert）在 `tls_verify=false` 时打告警日志。

#### M2. 计费链路读-改-写非原子，重叠调度可重复计费

- 位置：[BillingService.php](file:///workspace/nps-admin/app/Services/Portal/BillingService.php#L70-L103)（`FlowBaseline::firstOrNew` → 计算 delta → `update`，无锁无事务）；[SyncEngine.php](file:///workspace/nps-admin/app/Services/Sync/SyncEngine.php#L179-L222)（`syncFlow` 每行两次 update 查询，N+1 且无锁）
- 触发条件：自循环 Job（5min delay）与 console.php 每 10 分钟种子（[console.php](file:///workspace/nps-admin/routes/console.php#L15-L21)）重叠、或上轮执行超 5 分钟 → 同一 shard 被两个 worker 同时处理。baseline 快照回写丢失 → delta 重复累加。
- 附带：`chargeWallet` 的 `paid` 判定读 `$before` 未加锁（[BillingService.php](file:///workspace/nps-admin/app/Services/Portal/BillingService.php#L277-L288)），并发下欠费判定可能偏差（金额本身由 `decrement` 保证）。
- 建议：Job 实现 `ShouldBeUnique` 或用 `Cache::lock("flow:{server}:{shard}")`；`accumulateFlow` 改 `UPDATE ... SET last_in = GREATEST(?, last_in)` 式条件更新，或包事务 + `lockForUpdate`。

#### M3. 制品 version/filename 未做路径字符白名单

- 位置：[UpgradeController.php](file:///workspace/nps-admin/app/Http/Controllers/Admin/UpgradeController.php#L37-L53)（`version` 仅 `string|max:64|unique`，拼入 `artifacts/{$version}/nps`；`..`/`/` 未拦截）
- 现状：Laravel 12 使用的 Flysystem v3 本地适配器会拒绝越出磁盘根的路径（实际防线存在），但该防线属于"依赖第三方行为"而非显式校验。
- 建议：`'version' => 'required|regex:/^[A-Za-z0-9._-]+$/|...'`。

#### M4. 端口池自动分配未排除 nps 自身监听端口

- 位置：[ProvisioningService.php](file:///workspace/nps-admin/app/Services/Provisioning/ProvisioningService.php#L196-L243)（默认区间 10000-65535 全区间搜索，未剔除 `bridge_port/web_port/p2p_port/http_proxy_port/https_proxy_port`）
- 后果：自动分配可能命中 nps 已监听端口 → 创建后 `run_status=false` 触发补偿（设计上被 run_status 校验兜住），浪费一轮 Saga 且日志噪音。
- 建议：`holdPort` 增加"保留端口集"参数（值可从 `/ext/config` 白名单键推导）。

#### M5. nps.conf 及备份文件写回权限 0644

- 位置：[config.go](file:///workspace/server/ext/config.go#L96-L107)（`writeConf` tmp 文件 0644 + rename）、[config.go](file:///workspace/server/ext/config.go#L109-L126)（`backupConf` 备份 0644）
- 后果：文件含 `auth_key/web_password/public_vkey`，同机其他用户可读。
- 建议：敏感文件统一 0600（备份可 0640），或在写回时保留原文件 mode。

#### M6. 二进制升级无本地旧版备份，回滚强依赖 Laravel 制品库

- 位置：[upgrade.go](file:///workspace/server/ext/upgrade.go#L108-L122)（rename 直接覆盖，无 `binPath.bak`）；[UpgradeOrchestrator.php](file:///workspace/nps-admin/app/Services/Upgrade/UpgradeOrchestrator.php#L294-L299)（旧制品不存在则"需人工介入"）
- 后果：新二进制 sha256 正确但架构不符/启动崩溃时，服务器陷死且无法本机自救。
- 建议：替换前 `cp binPath binPath.bak-<version>`（保留 2 份），并在 `/ext/config` 同目录留恢复说明；编排器启动升级前强制检查旧制品在库。

#### M7. 门户注册/登录无限流，Sanctum token 永不过期

- 位置：[AuthController.php](file:///workspace/nps-admin/app/Http/Controllers/Portal/AuthController.php#L21-L76)（`register/login` 无 `throttle` 中间件；`createToken` 无过期时间）
- 建议：路由叠加 `throttle:10,1`；token 用 `expiresAt(now()->addDays(30))`，或对 `portal` token 启用 Sanctum 过期配置。

#### M8. nps 原生 ChangeStatus 不落盘（继承缺陷，ext 未修复）

- 位置：[client.go](file:///workspace/web/controllers/client.go#L154-L165)（`ChangeStatus` 改 `client.Status` 后未调 `StoreClientsToJsonFile`，nps 重启后 status 丢失）
- 影响：镜像与远端短暂不一致（由 L2/L3 修复）；nps 自身重启后启停状态回退。
- 建议：补一行落盘（一行改动，可并入下次 Go 侧维护）；或至少在缺陷清单中登记（DESIGN.md §15 可补充）。

### Low

| # | 问题 | 位置 | 建议 |
|---|---|---|---|
| L1 | 制品下载 token 无过期、出现在 URL（访问日志/Referer 泄漏面） | [ArtifactDownloadController.php](file:///workspace/nps-admin/app/Http/Controllers/Admin/UpgradeController.php)（token 生成于 artifactStore）、[ArtifactDownloadController.php](file:///workspace/nps-admin/app/Http/Controllers/ArtifactDownloadController.php#L16-L32) | token 增加 `expires_at`；下载后一次性轮换 |
| L2 | `SyncEngine::deleteMissing` 在远端结果为空时跳过删除，远端清空后镜像残留 | [SyncEngine.php](file:///workspace/nps-admin/app/Services/Sync/SyncEngine.php#L277-L281) | 空结果时执行全删（按 server_id） |
| L3 | `/ext/list` 每请求全量收集+排序 key（O(N log N)） | [ext.go](file:///workspace/web/controllers/ext.go#L227-L235) | 压测已验证 10 万级可接受；如需优化可维护有序索引 |
| L4 | `UpgradeRunJob::timeout()` 用动态方法定义超时，vendor 未安装无法核实当前 Laravel 版本是否支持（部分版本仅认 `$timeout` 属性） | [UpgradeRunJob.php](file:///workspace/nps-admin/app/Jobs/UpgradeRunJob.php#L26-L31) | 部署前实测；必要时改为按服务器数估算的静态 `$timeout` |
| L5 | `MonitorController::metrics` 返回 `server->only([..., 'client_online'])`，`NpsServer` 无该字段，恒缺失 | [MonitorController.php](file:///workspace/nps-admin/app/Http/Controllers/Admin/MonitorController.php#L27) | 删除该字段或改从 metrics 取 |
| L6 | 无 Plan seeder；`default_plan_id=1` 依赖人工建套餐（register 有 exists 判断，优雅降级 ✓） | [AuthController.php](file:///workspace/nps-admin/app/Http/Controllers/Portal/AuthController.php#L36-L44) | 提供 `PlanSeeder` |
| L7 | `.env.example` 未包含 `NPS_ACME_*`/`NPS_CERTD_*`/`NPS_ALERT_WEBHOOK_URL` 样例 | [​.env.example](file:///workspace/nps-admin/.env.example) | 补充注释样例 |
| L8 | ext 端点鉴权失败 `StopRun()` 返回空 200，无 401/403 语义 | [ext.go](file:///workspace/web/controllers/ext.go#L43-L46) | 返回显式状态码便于排障 |
| L9 | `ApprovalService::approve/reject` 状态检查与更新非原子（TOCTOU，双管理员并发） | [ApprovalService.php](file:///workspace/nps-admin/app/Services/Portal/ApprovalService.php#L56-L86) | `where status=submitted` 条件更新并检查影响行数 |
| L10 | 时钟偏移静态缓存永不失效（进程生命周期内不重校准） | [NpsApiClient.php](file:///workspace/nps-admin/app/Services/Nps/NpsApiClient.php#L253-L282) | 缓存加 TTL（如 10 分钟） |
| L11 | flow 分片号 `floor(time/300)%16`：单轮超过 5 分钟时同一 shard 可能重复或跳过 | [SyncFlowJob.php](file:///workspace/nps-admin/app/Jobs/SyncFlowJob.php) | 可接受（计费为增量基线制）；如严格化可持久化游标 |
| L12 | `repairClientRefs` 用原生 SQL 且表名插值（当前仅内部常量调用，无注入面） | [SyncEngine.php](file:///workspace/nps-admin/app/Services/Sync/SyncEngine.php#L417-L426) | 加白名单断言防未来误用 |
| L13 | Go 侧使用已废弃 `ioutil.*`（与上游风格一致，非缺陷） | ext 包多处 | 升级 Go 版本时统一替换 |

---

## 三、分维度审计结论

### 3.1 Go 侧（nps-admin-ext）

- **鉴权**：`ExtController.Prepare` 正确复用签名校验并强制 admin（签名通过 → session isAdmin；普通用户 session 被 `StopRun` 拦截，无绕过路径）。`/ext/*` 仅注册于 web 管理端口，未暴露到 bridge。✔（除 C1 链条中的"签名即管理员"为上游既有语义）
- **并发**：changelog 有锁但 seq 分配在锁外（H4）；`Flow`/`List` 对 `t.Flow/h.Flow` 读取正确持 RLock；`sortedKeys` 直取 `*sync.Map` 规避了 `GetMapKeys` 的 lock copy 问题（注释已自证）；`serviceInstance` 注入仅启动期一次写。整体并发质量良好，唯 H4 需修。
- **注入**：`/ext/config` 写入走键白名单 + 值类型校验（port/int/bool），无 shell 执行、无 SQL；`/ext/cert` PEM 校验后才落盘（私钥 0600 ✔）；`/ext/upgrade` 路径均为程序自身路径派生，无用户可控路径拼接。✔（SSRF 见 H3）
- **资源**：`/ext/system` 避开了 `dealClientData` 的 O(C×T) 与 ServerStatus 无锁切片（设计正确）；`Flow` 单次全 Range 为 O(T+H)；`download` 有 256MB 上限。无发现泄漏点。
- **侵入点核实**（git diff `98919af..HEAD` 逐行）：lib/file/db.go 8 处 `changelog.Append` 全部位于持久化成功路径之后 ✔；`index.go:317`、`client.go:161` 位于成功路径 ✔；router/cmd 各 2 行纯注册 ✔。**未发现对既有逻辑的语义性修改。**
- **已知上游缺陷复核**（PROJECT_DIARY §15）：`/index/start` 虚报成功、`/client/del` 级联、`client/list` O(C×T)、`flow_limit` 生命周期语义 —— 均未修复但已在 Laravel 侧以 run_status 回读、级联确认、`/ext/list`、独立月度配额四条路径绕开 ✔；`lib/rate` ticker 无 stop 分支、p2p 无锁 map、`handleBind` 空实现、`transport_windows.go` 不转发 —— 不在 ext 路径上，维持登记。

### 3.2 Laravel 侧

- **鉴权与越权**：`admin` 组（审批/套餐/充值/结算/证书/监控/升级）均有 `auth:sanctum + admin` ✔；门户资源经 Policy 归属校验（view/manage 双方法，is_admin 直通）✔；`ProvisionApprovalJob` 开通隧道前 `where('user_id', $user->id)->findOrFail()` 复核归属 ✔。**唯 `/api/nps/*` 裸奔（C1）。**
- **SQL 注入**：全部查询走 Eloquent/Query Builder 绑定；唯一原生 SQL `repairClientRefs` 表名为内部常量（L12 备注）。`like` 搜索未转义 `%_`（功能瑕疵非漏洞）。✔
- **Mass Assignment**：`User::$fillable` 含 `is_admin`，但注册路径显式字段创建 ✔；`Plan::create($validated)` 键集由 validate 白名单约束 ✔。
- **事务与幂等**：钱包、账单、套餐唯一约束齐备（bills `(user_id,period)` unique、flow_monthly/flow_baselines unique、port_allocations `(server_id,port)` unique、approvals `operation_key` unique）✔；Saga 补偿逻辑闭环（compensateTunnel 按端口清扫远端半成品）✔；ProvisionApprovalJob 三层幂等（status/回写 resource_id/镜像查重）✔。唯 baseline 读改写非原子（M2）。
- **配额统计正确性**：nps 重启清零场景已处理（`current < last` 时按 current 计）✔；月度配额与生命周期 flow_limit 双轨分离 ✔；`usedBytes = in+out` 口径与账单一致 ✔；超额幂等由 `quota_action` 状态机保证 ✔。
- **N+1**：`syncFlow` 每条 flow 两次独立查询（10 万级 client×16 分片可接受但可优化为批量 upsert）；`ServerController::index` 对每台 server 3 次 count（服务器数量少，可接受）；其余列表均有 `with` 预载 ✔。

### 3.3 接口契约一致性（Laravel ↔ Go）

| 契约项 | 核对结果 |
|---|---|
| 签名 `md5(auth_key+timestamp)`，±20s | ✔ 两侧一致（NpsApiClient.php:244-251 ↔ base.go:32-36） |
| 三种响应格式（status/rows+total/code）统一 | ✔ NpsResponse::fromArray 覆盖齐全；`/ext/*` 统一 `{status,data}` ✔ |
| `/client/add` 参数名（vkey/u/p/crypt/compress/config_conn_allow/flow_limit/max_conn/max_tunnel/rate_limit） | ✔（client.go:48 与 Laravel store 映射一致） |
| `/index/add` 参数名（type/client_id/port/target/remark/password/local_proxy） | ✔（index.go:83,99,113 ↔ TunnelController） |
| `/ext/changes` `since`、`/ext/flow` `shard/total`、`/ext/list` `type/cursor/limit` 走 query | ✔（Go GetIntNoErr/GetString 兼容 query；SDK 签名恒走 query） |
| `/ext/config` POST body `{entries,restart}`、敏感值 `******` 跳过语义 | ✔（ext.go:374-399 ↔ config.go:228-243） |
| `/ext/cert` POST body `cert_pem_b64/key_pem_b64/restart` | ✔（NativeChannel ↔ ext.go:416-444） |
| `/ext/upgrade` body `mode/binary/url/sha256/version/restart`；sha256 hex 大小写 | ✔（EqualFold 比较；Orchestrator 字段一致） |
| 版本签名 `{version}-ext{ext_version}` | ✔（waitHealthy ↔ /ext/version 输出） |
| changelog `type` 命名 task→tunnel 映射 | ✔（SyncEngine typeMap；db.go append 用 "task"） |
| 游标语义 `cursor=最后 id`、`extList(type, id-1, 1)` 精确取单条 | ✔（依赖 key 升序 + `key<=cursor` 跳过，两侧实现吻合） |
| `truncated` 回绕 → 全量对账 | ✔ 两侧闭环（changelog.Since ↔ syncChanges） |

**未发现契约不匹配项。** 唯一语义性风险即 H2（nps Edit 全量替换语义未被 Laravel 侧完全理解）。

### 3.4 依赖与配置

- `.gitignore` 含 `.env/.env.backup/.env.production` ✔，仓库内仅 `.env.example`（无密钥）✔；
- `composer.json`：laravel/framework ^12.0、sanctum ^4.0、php ^8.2 —— 依赖范围合理；vendor 未安装，**composer.lock 锁定版本未核验**（见"未覆盖范围"）；
- `config/nps.php` 默认值：`default_plan_id=1`、`auto_approve.enabled=true`、`quota_action=throttle`、`audit_reads=false` —— 自动审批默认开启需运营确认（属产品决策，登记为部署检查项）；`acme/certd` 凭据均走 env，未硬编码 ✔；
- `tls_verify` 默认 false（M1）；告警 webhook 凭据走 env ✔。

---

## 四、修复优先级建议

1. **立即（上线阻断）**：C1（路由加鉴权 + `$hidden` auth_key，两处改动约 5 行）。
2. **本周内**：H1（审计脱敏）、H2（editClient 补全或 nps 语义修正——后者影响面大建议先做前者）、H4（changelog 锁内分配 seq，约 5 行）。
3. **试运行前**：H3（download 收紧）、M2（Job 唯一性/锁）、M5（文件权限）、M6（本地备份）、M7（限流+token 过期）。
4. **随版本迭代**：M1/M3/M4/M8 及 Low 项。

---

## 五、审计覆盖范围与未覆盖项

**已覆盖**：
- Go：`server/ext/*` 全部 5 文件、`lib/changelog`（含测试）、`web/controllers/ext.go` 全文、侵入点 diff 逐行核实、`BaseController` 鉴权链、`db.go/index.go/client.go` 相关写路径、路由注册两条分支。
- Laravel：全部 7 个 Service、7 个 Job、12 个 Controller（含 Portal/Admin/Artifact）、2 个 Policy、Middleware、routes（api/console）、bootstrap、config/nps.php、13 个关键 migration、核心 8 个 Model、RegisterServerCommand。
- 交叉验证：SDK↔Go 参数契约全表核对（见 3.3）；唯一约束与幂等设计核对（见 3.2）。

**未覆盖**：
- 动态测试：未做渗透测试、fuzz、并发注入；压测数据引自此前阶段验收（DELIVERY.md），本次未复测。
- 真实 ACME/certd 对接（AcmeProvider 为生产骨架，证书签发链路未实测）。
- 多机滚动升级实机演练（Orchestrator 逻辑为静态审计）。
- composer.lock 锁定版本核对、npm 前端资产（仓库未含前端构建物）。
- nps 原生 proxy/bridge 全量代码（仅审计 ext 新增路径及其依赖的数据读写路径；上游既有缺陷沿用 PROJECT_DIARY §15 清单）。
- Windows 平台行为（upgrade/restart 的 Windows 分支为静态判断）。
