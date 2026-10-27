# nps 集中管理平台设计方案（Laravel）

> 版本：v3（v1–v3 合并稿）
> 日期：2026-10-27
> 前置阅读：[PROJECT_DIARY.md](PROJECT_DIARY.md)（nps 0.26.10 源码解析）
> 约束基线：**不更改 nps 现有功能**；允许纯新增扩展模块（nps-admin-ext）及 10 处一行式观测 hook
> 管理端技术栈：PHP / Laravel；管理通道：nps WebAPI（HTTP）

---

## 0. 文档沿革与需求基线

| 版本 | 需求输入 | 关键设计变化 |
|---|---|---|
| v1 | 不改 nps，Laravel 经 WebAPI 管理配置/用户/隧道 | 零改动可行性论证、WebAPI 能力边界盘点 |
| v2 | P2 自助门户；10 万级规模；nps 新增监控 JSON / 重启 / 配置 API；全部 webapi 控制（不做带外） | 约束从「零改动」变为「纯新增扩展」；发现现有 list 端点 10 万级不可用；设计 nps-admin-ext 与三层同步 |
| v3 | 10 万为平台总量；证书 ACME 自动签或人工上传 + certd 自动部署；门户需审批流/计费；nps 版本滚动 | 证书子系统、审批状态机、计费引擎、自建发布流水线 + 滚动升级编排 |

最终需求基线：

1. 平台总量 **10 万级 client/tunnel**，分布 50–100 台 nps；
2. 管理端 Laravel，含管理员后台与 **P2 用户自助门户（审批流 + 计费）**；
3. nps 侧新增：系统监控 JSON 端点、重启/重载 webapi、nps.conf 配置管理 webapi、证书下发、二进制升级端点；
4. 证书来源：**ACME 自动签 或 人工上传**，经 **certd 自动部署**；
5. **版本滚动升级**（灰度、回滚）；
6. 不做带外（SSH/Ansible）管理，全部经 webapi。

---

## 1. 可行性结论

**可行。** 依据：

- nps WebAPI 已覆盖客户端 / 隧道 / 域名解析的完整 CRUD + 启停 + 状态切换（19 个端点，含 2 个文档未列）；
- 鉴权机制（`auth_key` 签名）可被外部系统稳定复现，纯 HTTP 参数即获 admin 级会话；
- v1 的两条硬边界（nps.conf 无 API、无重启 API、监控无 JSON）在 v2 需求下通过 **nps-admin-ext 纯新增扩展**解决；
- 唯一被推翻的 v1 假设：**全量轮询 `client/list` 做同步**——该端点在 10 万级下复杂度为 O(C×(H+T))，不可用（§3），必须以扩展端点替代。

---

## 2. 事实基础：nps WebAPI 能力边界（源码逐条核实）

### 2.1 鉴权机制（[base.go#L24-L49](web/controllers/base.go#L24-L49)）

每个请求携带两个参数即可通过任意控制器鉴权：

```
auth_key  = md5( nps.conf的auth_key + timestamp )   // 32 位 hex
timestamp = 当前 unix 秒
```

- 时间窗口 **±20 秒**（时钟同步是硬要求）；
- 通过即 `SetSession("isAdmin", true)`——Laravel 侧获得的就是 **admin 全权限**；
- `/auth/gettime` **无需鉴权**返回服务端 unix 时间（[auth.go#L37-L43](web/controllers/auth.go#L37-L43)），SDK 启动时校准时钟偏移；
- `/auth/getauthkey` 无需鉴权返回 AES 加密的 auth_key（Laravel 已知明文用不到，但属暴露面）。

### 2.2 既有端点全集（AutoRouter，POST）

| 域 | 端点 | 关键参数 | 响应格式 |
|---|---|---|---|
| 客户端 | `/client/list` | search, order, offset, limit | `{"rows":[...],"total":n}` |
| | `/client/getclient` | id | `{"code":1,"data":{...}}` |
| | `/client/add` | vkey, remark, u, p, compress, crypt, config_conn_allow, rate_limit, flow_limit, max_conn, max_tunnel, web_username, web_password | `{"status":1,"msg":...}` |
| | `/client/edit` | 同上 + id | 同上 |
| | `/client/changestatus` | id, status | 同上（文档未列） |
| | `/client/del` | id | 同上 |
| 隧道 | `/index/gettunnel` | client_id, type, search, offset, limit | `{"rows":[...],"total":n}` |
| | `/index/getonetunnel` | id | `{"code":1,"data":{...}}` |
| | `/index/add` | type, port, server_ip, target, local_proxy, remark, password, local_path, strip_pre, client_id | `{"status":1,"msg":...}` |
| | `/index/edit` | 同上 + id | 同上 |
| | `/index/del` / `/index/stop` / `/index/start` | id | 同上 |
| 域名 | `/index/hostlist` | search, client_id, offset, limit | `{"rows":[...],"total":n}` |
| | `/index/gethost` | id | `{"code":1,"data":{...}}`（文档未列） |
| | `/index/addhost` | remark, host, scheme, location, client_id, target, header, hostchange, cert_file_path, key_file_path | `{"status":1,"msg":...}` |
| | `/index/edithost` | 同上 + id | 同上 |
| | `/index/delhost` | id | 同上 |

三种响应格式并存（`status/msg`、`rows/total`、`code/data`），SDK 必须统一封装。

### 2.3 原生硬边界与扩展对策

| 需求 | 原生现状 | 对策 |
|---|---|---|
| 修改 nps.conf | 无 API | 新增 `/ext/config`（§5） |
| 重启/重载 nps | `service.Control` 通道存在但无 HTTP 入口 | 新增 `/ext/restart`（§5、§11） |
| CPU/内存/连接数 JSON | `/index/index` 只渲染 HTML | 新增 `/ext/system`（§5） |
| 事件回调 | nps 从不主动外呼 | 版本号 + 增量端点轮询（§6） |
| 单条流量明细 | 只有聚合值 | `/ext/flow` 分片采集（§6） |

### 2.4 必须纳入设计的 nps 既有行为

1. **`/client/del` 级联删除**该客户端全部隧道与域名解析（[client.go#L166-L173](web/controllers/client.go#L166-L173)）——删除前列影响面确认。
2. **`changestatus status=0` 立即踢客户端下线**（`DelClientConnect`）。
3. **`/index/start` 忽略失败并强制 `Status=true`**（[server.go#L218-L226](server/server.go#L218-L226)）——端口占用时 nps 仍报成功；Laravel 每次 start 后必须回读 `gettunnel` 校验 `RunStatus`，必要时自行 TCP 探测端口。
4. **secret/p2p 的 `password` 全局唯一**（`NewTask` 校验）；端口查重仅「同 client 同 port」，跨 client 冲突靠 `TestServerPort` 试监听拦截（存在 TOCTOU 窗口）——Laravel 自建端口池预占（§4.5）。
5. **`client/list` 每次请求触发 `dealClientData` 全表重算**（§3 详述）。
6. **单位**：`flow_limit` 为 MB（生命周期累计）、`rate_limit` 为 KB/S（服务端 `<<20` / `×1024` 换算）。
7. `vkey` 可由调用方指定（查重校验），留空则 nps 生成 16 位随机串——Laravel 统一生成，保证跨服务器可控。
8. md5 签名无 nonce，20s 窗口内理论可重放——nps 既有设计，不改代码只能网络层缓解（§12.4）。
9. **`flow_limit` 是生命周期累计值，从不重置**（[base.go](server/proxy/base.go) 的 `CheckFlowAndConnNum`）——不能直接当月度配额用（§8.3）。

---

## 3. 关键发现：现有 list 端点在 10 万级下不可用

`/client/list` 每次请求触发 `dealClientData()`（[server.go#L277-L313](server/server.go#L277-L313)），复杂度 **O(C × (H+T))**：对每个 client 遍历全部 hosts 与 tasks 求和流量。

按 10 万 client / 20 万 tunnel 估算：单次请求 ≈ 10万 × 30万 = **300 亿次 map 遍历**，且该函数把 `client.Flow` 清零后重算，并发请求互相干扰（数据竞争）。

> 结论：v1 的「轮询 client/list 做镜像同步」在 10 万级下不是慢，是灾难。`GetDashboardData` 内部同样调用 `dealClientData` 且含 500ms sleep，监控端点也不能简单包一层。**10 万级方案的前提是 nps-admin-ext 扩展模块（§5）。**

---

## 4. 总体架构

### 4.1 架构图

```
┌──────────────────────────────────────────────────────────┐
│                 Laravel 集中管理平面                       │
│                                                          │
│  管理员后台 │ P2 用户自助门户（审批流/计费）                  │
│  ────────────────────────────────────────────────────    │
│  NpsApiClient（签名 SDK，每服务器一实例，时钟校准）           │
│  镜像层 nps_clients / nps_tunnels / nps_hosts（20 万行）    │
│  端口池 │ Saga 写补偿 │ 三层同步 │ 在线/流量采集              │
│  证书子系统（ACME/人工 → certd/native 部署）                 │
│  升级编排器（canary/灰度/回滚） │ 审计 │ 告警 │ 报表           │
│  Queue（写操作异步+重试） │ Scheduler（轮询调度）             │
│  MySQL（业务+镜像） │ Redis（实时流量/在线/端口池锁）          │
└───────────────┬──────────────────────────────────────────┘
                │ HTTPS（web_open_ssl）+ auth_key 签名（±20s）
                ▼
   ┌─────────┐  ┌─────────┐  ┌─────────┐
   │ nps #1  │  │ nps #2  │  │ nps #N  │   N = 50–100
   │ +ext 模块│  │ +ext 模块│  │ +ext 模块│   单机 1–2 千 client
   └─────────┘  └─────────┘  └─────────┘
```

### 4.2 数据所有权模型

- **nps = 运行时权威**（连接状态、流量计数、任务运行状态）；
- **Laravel = 管理权威 + 镜像**（用户归属、套餐、审批、审计、端口分配）；
- 写路径：Laravel → nps API → 回读确认（`/ext/changes`）→ 更新镜像；
- 漂移防护：nps 自带面板仍可用（admin 密码封存强随机），每日滚动对账发现外部直改并告警。

### 4.3 ID 映射

- 本地表 `nps_servers`（id, name, api_url, auth_key_encrypted, bridge_type, bridge_port, version, cert_fingerprint…）；
- 业务对象用本地代理键 + `(server_id, remote_id)` 唯一索引——各 nps 的 client/tunnel/host ID 空间独立，vkey 由 Laravel 统一生成保证全局唯一。

### 4.4 写操作 Saga（nps API 无事务）

```
本地 pending（operation_key 幂等键）
  → 调 nps API
  → /ext/changes 回读验证
  → 提交镜像 + 审计
  └ 失败 → 补偿：add 失败清理本地预占；del 幂等（不存在视为成功）；
           edit 失败重试（队列指数退避，3 次后转人工）
```

### 4.5 端口池

- 每服务器登记 `allow_ports` 区间，Laravel 维护分配表（DB 唯一约束 `(server_id, port)`）；
- 分配流程：本地预占 → 调 `/index/add`（nps 侧 `TestServerPort` 试监听为第二道保险）→ 失败释放预占；
- 不依赖 nps 的试监听做唯一判据（存在 TOCTOU 窗口）。

---

## 5. nps 侧扩展模块：nps-admin-ext

### 5.1 定位与侵入点

```
nps-admin-ext（新增 Go 包 + 新增 controller，注册进现有 AutoRouter）
├─ 不修改任何现有 controller / proxy / bridge 逻辑
├─ 复用现有数据源：tool.ServerStatus、Bridge.Client、JsonDb、service 实例
└─ 唯一侵入：lib/file 的 10 个写函数各加一行 changelog.Append（语义零变化）
```

hook 清单（每处一行）：

```
lib/file/db.go: NewClient / UpdateClient / DelClient
                NewTask   / UpdateTask   / DelTask
                NewHost   / UpdateHost   / DelHost
server/server.go: StopServer / StartTask   （Status 翻转计入变更）
```

### 5.2 扩展端点清单（均走既有 auth_key 签名鉴权）

| 端点 | 数据源 | 复杂度 | 用途 |
|---|---|---|---|
| `/ext/system` | 新函数 `GetSystemMetrics()`：gopsutil 直读 CPU/内存/负载/连接数 + `tool.ServerStatus` + `version.VERSION` | O(1) | 系统监控（**不调** `dealClientData`） |
| `/ext/version` | 新增 `atomic.Int64` 变更计数器 | O(1) | 轻量变更探测（每 10s 轮询，几十字节） |
| `/ext/changes?since=seq` | 内存环形变更日志（容量 1 万条 `{seq,op,type,id}`） | O(增量) | 增量同步 |
| `/ext/online` | `Bridge.Client` key 集合（`ping()` 每 5s 已维护） | O(在线数) | 在线状态（断连不产生 file 变更，须独立轮询） |
| `/ext/flow?shard=n/16` | 一次 `Range` tasks+hosts 聚合 `{client_id:{in,out}}` | O(T+H)/片 | 流量采集 |
| `/ext/list?type=…&cursor=…` | `sync.Map.Range` + 游标分页，只返回必要字段（含 `Version`），**不做 Flow 聚合** | O(页大小) | 全量对账 / 冷启动导入 |
| `/ext/config` GET/POST | 读写 `conf/nps.conf`：读=脱敏键值；写=白名单校验+备份+原子写 | O(行数) | 配置管理 |
| `/ext/cert` GET/POST | GET 返回当前证书指纹；POST 写 `web_cert_file/web_key_file` + 更新 conf + 可选联动重启 | O(文件) | 证书激活（唯一入口） |
| `/ext/restart` POST | 先回 202，goroutine 延迟 1s 调 `service.Control(s,"restart")` | — | 重启/重载 |
| `/ext/upgrade` POST | 接收二进制（分块）→ sha256 校验 → 写 tmp → `os.Rename` 覆盖 binPath → 联动 restart | O(文件) | 版本滚动（§9） |

变更日志与计数器**重启即失**（内存态）：Laravel 检测 `/ext/version` 回退（新 < 旧）即触发该台全量对账——边界行为在编排器中显式处理。

### 5.3 service 实例注入

`cmd/nps/nps.go` 的 `nps.Start(s service.Service)` 已收到实例（[nps.go#L161-L165](cmd/nps/nps.go#L161-L165)）。新增包级 `var ServiceInstance service.Service` 在 `Start` 赋值——纯新增。`/ext/restart` 走 systemd/init.d 通道（环境一致）；未安装服务的交互模式回退 `execve` 自重启。

### 5.4 `/ext/upgrade` 的二进制替换机制

Linux 不允许覆盖**正在运行**的 ELF（ETXTBSY），但允许 `rename` 替换其路径：运行中进程持有旧 inode，下次 systemd 拉起使用新文件。因此：

```
POST /ext/upgrade（分块上传二进制）
  → sha256 与 Laravel 侧登记值比对
  → 写 binPath 同目录临时文件
  → os.Rename(tmp, binPath)      // 原子替换
  → 触发 §11 重启流程
```

---

## 6. 10 万级同步架构（三层模型）

### 6.1 总原则

> nps 是运行时权威，Laravel 是管理权威 + 镜像。**写后回读为主、版本号触发增量为辅、低频滚动对账兜底**。绝不高频调用任何 O(C×T) 路径。

```
L1 写后回读（事件驱动，秒级）
   Laravel 写操作 → 调 nps API → /ext/changes 回读确认 → 更新镜像 → 记审计

L2 版本号增量（每台每 10s 轮询，捕获外部变更）
   /ext/version（几十字节）
   ├─ 未变化 → 无动作
   └─ 变化 → /ext/changes?since=last_seq → 批量 upsert（1,000 行/批）

L3 滚动对账（每台每 6h 兜底）
   /ext/list 游标分页全量对比 → 修复漂移 → 告警

独立通道：
   /ext/online   每 30s（在线 id 集合，10 万 id 压缩后 ~100KB，可分片）
   /ext/flow     每 5min（16 分片轮转，写入 Redis 当前值 + 小时聚合表）
```

### 6.2 规模账（50–100 台、平台总量 10 万）

| 项 | 估算 | 结论 |
|---|---|---|
| version 轮询 | 100 台 × 每 10s ≈ 10 QPS × <100B | 可忽略 |
| changes 拉取 | 变更驱动；假设日变更 1% ≈ 1,000 条/天/台 | 可忽略 |
| online 轮询 | 100 台 × 每 30s，单次 O(在线数) | 可忽略 |
| flow 采集 | 每台每 5min 一片（1/16 全量），单次 O(T+H)/16 ≈ 1.5 万次遍历 | 轻载 |
| 对账 | 每台每 6h 一次全量（游标分页，每页 500） | 轻载 |
| Laravel 镜像 upsert | 峰值 1,000 行/批 × 批量 insert on duplicate key | MySQL 无压力 |

### 6.3 Laravel 侧数据与队列

| 表 | 行数量级 | 策略 |
|---|---|---|
| `nps_clients` / `nps_tunnels` 镜像 | 10 万 / 20 万 | 批量 upsert；`(server_id, remote_id)` 唯一索引 |
| `flow_hourly` | 240 万/天 | 只保留 90 天 + 按月分区；活跃 client 才写 |
| `flow_daily` | 10 万/天 | 计费结算输入 |
| `approvals` / `orders` / `bills` / `wallet` | 业务量级 | 标准设计 |

- 实时流量值放 Redis（`flow:{server}:{client}`），明细不落库；
- 每台服务器一个 sync worker（队列分 queue per server），轮询任务带 jitter 错峰，避免雷鸣群效应；
- 写操作队列指数退避重试，3 次失败转人工工单。

---

## 7. 证书子系统

### 7.1 架构：Laravel 是唯一 SoT，certd 是执行器

```
来源层（CertificateProvider 抽象）
├─ AcmeProvider     ACME DNS-01 自动签发（推荐通配符 *.mgmt.example.com，
│                   一张证书覆盖全部 nps 管理域名，轮换一次全量滚动）
└─ ManualProvider   管理后台人工上传 PEM（含私钥），入库版本化
        ↓
证书库（certificates 表：多版本、生效版本、到期时间、覆盖域名）
        ↓
部署层（DeploymentChannel 抽象）
├─ CertdChannel     触发 certd 部署流水线（certd 配置自定义 HTTP 部署，
│                   目标指向各 nps 的 /ext/cert）
└─ NativeChannel    Laravel 直推 /ext/cert（certd 故障兜底）
        ↓
激活入口（唯一）：POST /ext/cert
   body: {cert_pem_b64, key_pem_b64, auto_restart: bool}
   行为: 校验证书私钥匹配 + 域名 → 原子写 web_cert_file/web_key_file
        → 更新 conf（web_open_ssl=true）→ 可选联动滚动重启
```

### 7.2 设计要点

- **双 SoT 防护**：certd 只做搬运；证书版本、到期、覆盖范围以 Laravel 证书库为准；每日对账任务校验各 nps 实际证书指纹（`/ext/cert` GET）。
- **轮换流程**：到期前 30 天自动续签 → certd/native 推送 → 滚动重启（逐台，批间健康检查，复用 §9 编排器）→ 指纹核验。
- **首次 HTTPS 启用顺序**（防管理面自锁）：先 `/ext/cert` 推证书 → 再 `/ext/config` 开 `web_open_ssl` → 联动重启。顺序由编排器保证。
- 证书私钥 Laravel 侧加密存储（encrypted cast），传输全程 TLS。

---

## 8. P2 用户自助门户：审批流 + 计费

### 8.1 审批流状态机

```
用户提交申请（开通客户端 / 新建隧道 / 套餐变更 / 配额提升）
   ▼
┌─────────┐   ┌──────────┐   ┌────────────┐   ┌──────────────┐
│submitted│ → │ approved │ → │provisioning│ → │ provisioned  │
└─────────┘   └──────────┘   └────────────┘   └──────────────┘
     │              │               │失败
     ▼              ▼               ▼
 rejected      expired(超时)     failed → 重试/人工介入
```

- 审批人：管理员（可细分审批权限）；**自动审批规则可选**（如「套餐内隧道数未超、端口在允许池、无风控命中」自动通过）——规则集 `[TBD]` 由运营定义。
- **provisioning 幂等**：申请单携带 `operation_key`，重试凭它去重（防重复调 `/client/add`）。
- 开通链路：审批通过 → Queue → 端口池预占 → 调 nps API → `/ext/changes` 回读确认 → 更新镜像 + 归属 → 通知用户。
- provisioning 后的撤销走「注销申请」二次审批。

### 8.2 计费模型与套餐映射

```
套餐（plans）: 月费 + 包含隧道数 + 月流量额度 + 带宽上限
账单（bills）: 月度结算 = 月费 + 超额流量 × 单价
余额（wallet）: 预充值 / 欠费停用策略 [TBD]
```

套餐 → nps 参数双层防御（Laravel 策略层 + nps 强制层）：

| 套餐项 | Laravel 策略层 | nps 强制层（/client/edit） |
|---|---|---|
| 隧道数上限 | 审批时校验 | `max_tunnel` |
| 带宽上限 | — | `rate_limit`（KB/S） |
| 并发连接上限 | — | `max_conn` |
| 月流量额度 | **Laravel 执行层**（§8.3） | 不使用 `flow_limit` ⚠️ |
| 加密/压缩 | 套餐开关 | `crypt` / `compress` |

### 8.3 关键语义冲突：nps `flow_limit` ≠ 月度配额

源码事实：`CheckFlowAndConnNum` 判定 `(FlowLimit<<20) < (Export+Inlet)`，而 `Export/Inlet` 是**客户端生命周期累计值，从不重置**。把「月流量 100G」设成 nps 的 `flow_limit`，第二个月起用户即被永久拒绝服务。

**月度配额由 Laravel 执行层实现**：

```
每月 1 号 00:00   记录各 client 流量基线（flow_base）入 Redis/DB
每 5 分钟         /ext/flow 采样 → 增量 = 当前值 − 基线
                  ├─ 未超额 → 更新 Redis 实时值
                  └─ 超额 → 动作（可配置）：
                       a) 限速：/client/edit 调低 rate_limit
                       b) 停用：/client/changestatus status=0（踢下线）
                       c) 计费放行：仅记超额量，月末结算
结算日           flow_daily 汇总 → 账单生成 → 通知
```

### 8.4 安全边界

- 用户请求 → Laravel Policy 校验归属（`user_id ↔ (server_id, client_id)`）→ Laravel 用 admin 签名调 nps；
- **用户永不接触 nps `auth_key`**；nps 侧 `WebUserName/WebPassword` 体系不启用（Laravel 用户体系替代）；
- 用户可见：自己的 vkey、在线状态、流量、隧道列表、secret/p2p 密码、npc 安装命令/配置生成；
- 用户可操作：受套餐与审批约束的隧道增删（端口由端口池分配，不可自选越界端口）。

---

## 9. nps 版本滚动升级

### 9.1 决定性结论：官方 `UpdateNps()` 不可用

[install.go#L137-L149](lib/install/install.go#L137-L149) 的实现：从 GitHub API 取 **latest** release → 下载官方 tar.gz → 覆盖二进制与 web 静态资源 → 提示手动重启。问题：

1. 只能升到官方 latest，**无版本选择**；
2. 失败路径全是 `log.Fatal`（进程退出），**无回滚**；
3. **官方二进制不含 nps-admin-ext**——升级即丢失扩展模块；
4. 无灰度、无健康验证。

> 版本滚动必须走**自建发布流水线 + `/ext/upgrade` + Laravel 编排器**。

### 9.2 自建发布流水线

```
fork ehang-io/nps（锁定基线 0.26.10）
  + nps-admin-ext 扩展模块
  → CI（多平台构建：linux/amd64 为主）
  → 制品库（Laravel 管理：版本号、sha256、兼容矩阵、变更说明）
  → 升级编排器分发
```

### 9.3 滚动升级编排（UpgradeOrchestrator）

```
1. 预检
   ├─ 目标版本兼容矩阵（§9.4）
   ├─ 扫描各台在线 npc 的 Version 分布（/ext/list 返回 Version）
   │   不满足兼容范围 → 阻断或提示先升级 npc
   └─ 备份该台 nps.conf（/ext/config 读回存档）
2. 灰度：先升 1 台 canary
   → 观察 15 分钟：在线率恢复曲线、/ext/system 指标、错误率、webapi 健康
3. 分批滚动：每批 5–10%，批间健康检查 + 间隔
4. 单台流程：
   POST /ext/upgrade（二进制 + sha256）
   → rename 替换 → /ext/restart
   → 健康验证：/auth/gettime + /ext/system（版本号核对）+ /ext/version
   → 失败自动回滚：回推旧二进制（制品库留存）+ restart + 告警
5. 全量完成 → 汇总报告（各台前后版本、耗时、回滚记录）
```

### 9.4 版本兼容矩阵（升级期间新旧共存）

- **bridge 握手校验 core version**：npc `NewConn` 与 nps `cliProcess` 比对 `md5(GetVersion())`（core `0.26.0`）。**滚动升级期间 nps 的 core version 必须保持不变**，否则旧 npc 全量握手失败；
- Laravel 维护矩阵：`nps 版本 → 兼容 npc core version 范围`，升级前扫描在线 npc Version 分布做阻断判断；
- `/ext/*` 端点响应携带 `ext_version` 字段，Laravel SDK 按字段做能力探测（端点不存在时优雅降级）；
- 新版本 conf 键变更 → 升级编排器做 conf diff + 迁移（按版本发布说明，逐版本 `[TBD]`）。

---

## 10. HTTPS 全链路改造

| 层 | 方案 |
|---|---|
| nps 管理面 | 启用既有 `web_open_ssl=true` + `web_cert_file/web_key_file`（[tcp.go#L72-L75](server/proxy/tcp.go#L72-L75)），证书经 §7 子系统下发 |
| 首次启用 | 先证书后开关（§7.2，防自锁），编排器保证顺序 |
| 证书生命周期 | ACME DNS-01 通配符（推荐）或人工上传；到期前 30 天自动续；certd/native 推送；滚动重启生效 |
| 网络层 | nps webapi 端口防火墙仅放行 Laravel 出口 IP（部署基线） |
| 传输安全 | md5 签名重放风险由 HTTPS + 来源限制缓解 |

---

## 11. 重启与配置变更安全流程

### 11.1 `/ext/restart` 实现

先写 HTTP 202 响应（"restarting"），goroutine 延迟 1s 调 `service.Control(s, "restart")`——保证响应先于进程终止发出；Laravel 侧按「连接拒绝 = 重启进行中」状态机处理，带超时重试。

### 11.2 配置变更流程（`/ext/config`）

```
读当前 conf（脱敏存档）
  → 白名单键校验（bridge_port 等冲突预检）
  → 备份 conf/nps.conf.bak.{timestamp}
  → 原子写（tmp + rename）
  → 联动 /ext/restart（beego 配置启动时加载，改文件不热生效）
  → 健康验证（/auth/gettime + /ext/system + 关键业务端口探活）
  → 失败回滚：恢复备份 + restart + 告警
```

### 11.3 重启影响面管理（10 万级）

- 重启断开该台全部 bridge 连接，npc 自动重连——**重连风暴**由滚动策略缓解（一次一台/一批，批间等在线率恢复）；
- systemd unit 现值 `Restart=always / RestartSec=120 / LimitNOFILE=65536`（[install.go#L112-L135](lib/install/install.go#L112-L135)）——部署基线建议 `LimitNOFILE=262144`（归入升级编排的 unit 模板，非 nps.conf）；
- 敏感键变更（`auth_key`、`web_password`）：Laravel 先用旧密钥改配置 → 重启后立即用新密钥验证 → 失败回滚（避免自锁）。

---

## 12. Laravel 技术设计

### 12.1 模块划分

| 模块 | 职责 |
|---|---|
| `NpsApiClient` | 每服务器一实例：签名注入、时钟校准（/auth/gettime）、三种响应格式统一、超时重试、`ext_version` 能力探测 |
| `SyncEngine` | 三层同步（L1/L2/L3）+ online/flow 独立通道，每服务器一 worker |
| `ProvisioningService` | Saga 写补偿 + 端口池 + operation_key 幂等 |
| `ApprovalService` | 审批状态机 + 自动审批规则 |
| `BillingService` | 月度基线、超额执行、结算、账单 |
| `CertificateService` | 双来源 + 双部署通道 + 指纹对账 |
| `UpgradeOrchestrator` | 发布制品管理 + canary/灰度/回滚 |
| `AuditLog` | 全部写操作记录（who/when/which server/API 请求与响应） |

### 12.2 队列与调度

- Queue：写操作（provisioning/补偿）、证书推送、升级单台流程——指数退避，3 次失败转人工；
- Scheduler：version 轮询（10s）、online（30s）、flow（5min 分片）、对账（6h）、证书对账（日）、月度基线与结算（cron）；
- 全部轮询任务带 jitter 错峰。

### 12.3 安全

- `auth_key` 加密存储（Laravel encrypted cast）；
- 管理流量全链路 HTTPS + nps webapi 防火墙白名单（仅 Laravel 出口）；
- 用户永不接触 `auth_key`（§8.4）；Laravel 面板自身 auth + 可选 2FA；
- 高危操作（restart/upgrade/config 写）二次确认 + 审计。

### 12.4 已知风险与缓解汇总

| # | 风险 | 缓解 |
|---|---|---|
| 1 | `client/list` O(C×T) | 全部同步走 `/ext/*`，永不高频调用原生 list |
| 2 | `GetDashboardData` 同样 O(C×T) + 500ms sleep | `/ext/system` 新函数，不调 `dealClientData` |
| 3 | `/index/start` 虚报成功 | 写后回读 `RunStatus` + 端口探活 |
| 4 | `flow_limit` 累计语义 | 月度配额走 Laravel 执行层（§8.3） |
| 5 | md5 签名可重放 | HTTPS + 来源 IP 白名单 |
| 6 | `/client/del` 级联 | 删除前列影响面确认 |
| 7 | 变更日志重启丢失（version 回退） | 编排器检测回退 → 触发全量对账 |
| 8 | 升级丢扩展模块 | 禁用官方 UpdateNps，自建流水线（§9） |
| 9 | core version 不兼容断连 | 兼容矩阵 + 升级前 npc Version 扫描 |
| 10 | 重连风暴 | 滚动重启 + 批间在线率恢复等待 |
| 11 | 配置变更自锁（HTTPS/auth_key） | 顺序编排 + 新密钥验证 + 自动回滚 |
| 12 | 审批开通重复执行 | operation_key 幂等 |
| 13 | certd 与 Laravel 双 SoT | certd 仅执行；每日指纹对账 |
| 14 | nps 单机容量 | 单机 ≤2 千 client；LimitNOFILE 提升；压测验收 |

---

## 13. 关键流程时序

### 13.1 用户开通隧道（P2 全链路）

```
用户提交申请（自助门户）
  → ApprovalService：submitted →（自动规则或人工）approved
  → Queue: ProvisioningService
      ├─ 套餐配额校验（Laravel 策略层）
      ├─ 端口池预占（DB 唯一约束）
      ├─ POST /index/add（type, port, target, client_id, password…）
      ├─ /ext/changes 回读确认 + RunStatus 校验
      └─ 镜像更新 + 归属绑定 + 审计 + 通知用户
  → 失败：释放端口预占 → failed → 重试/人工
```

### 13.2 删除客户端

```
管理员/用户发起 → 列出该 client 全部隧道与域名（镜像查询）
  → 确认 → POST /client/del（nps 级联删除）
  → /ext/changes 确认 → 镜像清理 + 端口池释放 + 计费结算收尾
```

### 13.3 常态同步（每台服务器）

```
每 10s   /ext/version → 变化则 /ext/changes 增量 upsert
每 30s   /ext/online  → Redis 在线集合 → 门户在线状态
每 5min  /ext/flow(分片) → Redis 实时值 + flow_hourly + 超额检查(§8.3)
每 6h    /ext/list 游标全量对账 → 漂移告警
```

---

## 14. 实施计划

| 阶段 | 内容 | 出口标准 |
|---|---|---|
| 0 | nps-admin-ext 扩展模块（全部 `/ext/*` 端点 + 10 处 hook）+ 自建 CI 流水线 | 扩展端点单机验收；二进制可复现构建 |
| 1 | Laravel 核心：服务器注册、签名 SDK、三层同步、client/tunnel/host CRUD、审计 | 单台 nps 全量纳管；同步延迟 < 30s |
| 2 | P2 自助门户：用户体系、归属、审批流、计费引擎、套餐 | 用户自助开通全链路（审批→provisioned） |
| 3 | 证书子系统（ACME/人工 + certd/native）+ HTTPS 全量启用 + 监控告警 | 全平台管理面 HTTPS；证书自动轮换演练 |
| 4 | 版本滚动升级编排（canary/灰度/回滚）+ 配置管理 | 一次真实灰度升级演练含回滚 |
| 5 | 压测验收：10 万 client 模拟（同步延迟、nps CPU/内存、flow 采集、重连风暴） | 指标达标 `[TBD]` |

---

## 15. 待确认事项（TBD 汇总）

| # | 事项 | 影响 |
|---|---|---|
| 1 | 自动审批规则集（阈值、风控命中条件） | §8.1 |
| 2 | 欠费停用策略（限速/停用/宽限期） | §8.3 |
| 3 | 超额流量单价、发票 | §8.2 |
| 4 | 各版本 conf 键迁移脚本 | §9.4 |
| 5 | 压测验收指标基线 | §14 阶段 5 |
| 6 | certd 具体集成方式（webhook 触发 vs 轮询订阅） | §7.1 |
