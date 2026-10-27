# nps 项目日记

> 日期：2026-10-27
> 主题：协议细节、代理实现、Web API、nps 服务端深入解析
> 方式：只读梳理源码，未修改任何代码
> 版本：nps 0.26.10 / core 0.26.0

---

## 0. 一句话架构

nps 是控制面与数据面分离的反向代理：npc 主动连接 nps，先建立一条 **signal（main）** 控制连接，再建立一条 **mux（chan）** 数据隧道；nps 收到用户连接后，通过 mux 向 npc "借"一条子流，先下发一段 **Link JSON** 描述目标，随后这条子流就变成透明的双向字节通道。

```
用户 ──> nps 业务监听端口
          │
          ├─ bridge.SendLinkInfo()  ── mux.NewConn() ──> npc
          │       └─ SendInfo(Link{ConnType,Host,Crypt,Compress,...})
          │
          └─ conn.CopyWaitGroup() <── 双向复制 ──> npc ──> 内网 target
```

整体分层：

| 层 | 载体 | 作用 |
|---|---|---|
| 控制面 | `main` 连接（TCP/KCP） | 鉴权、健康上报、P2P 指令、UDP 通知 |
| 数据面 | `chan` 连接上的 nps-mux 子流 | 每个用户连接一条子流 |
| 配置面 | `conf` 连接 | 配置文件模式动态下发 client/host/task |
| 文件面 | `file` 连接上的独立 mux | 文件服务器，跳过 Link 下发 |
| 旁路 | `sert` / `p2pm` / `rgst` | Secret 直连、P2P 打洞、IP 白名单注册 |

---

## 1. 协议细节

### 1.1 连接类型总表

常量定义在 [const.go](lib/common/const.go#L3-L31)。

| 4 字节标记 | 含义 | 服务端处理 |
|---|---|---|
| `main` | 主控制连接 | 保存 signal，5 秒 TCP keepalive，启动健康检查读取循环 |
| `chan` | 数据隧道 | 在该 TCP/KCP 连接上创建 nps-mux |
| `conf` | 配置文件模式 | 下发/新增 client、host、task，返回临时 vkey |
| `rgst` | 注册公网 IP | 读取小时数，写入 Bridge.Register，供 `ip_limit` 校验 |
| `sert` | Secret 模式 | 读取 32 字节 md5(password)，匹配任务后直接中继 |
| `file` | 文件服务 | 创建独立 mux，供客户端 `http.FileServer` 使用 |
| `p2pm` | P2P 元信息 | 校验密码，向 provider 的 signal 发送 UDP 地址和密钥 |
| `p2pv` / `p2pp` | P2P 角色标记 | 只出现在 P2P UDP 报文里，用于服务端交换双方地址 |

### 1.2 握手时序（逐字节）

实现分居 [client/control.go#L184-L252](client/control.go#L184-L252) 与 [bridge/bridge.go#L166-L210](bridge/bridge.go#L166-L210)。

| # | 方向 | 内容 | 编码 |
|---|---|---|---|
| 1 | C→S | `TST` | 3 字节裸 ASCII，仅用于探活/端口复用识别 |
| 2 | C→S | core version（`0.26.0`） | `int32 LE 长度` + 内容 |
| 3 | C→S | 完整版本（`0.26.10`） | `int32 LE 长度` + 内容 |
| 4 | S→C | `md5(core version)` | 固定 32 字节 |
| 5 | C→S | `md5(vkey)` | 固定 32 字节 |
| 6 | S→C | `sucs` 或 `vkey` | 固定 4 字节；`vkey` 表示鉴权失败 |
| 7 | C→S | 连接类型 | 固定 4 字节裸 ASCII，如 `main` |

细节：

- 客户端 `net.Dial` 后设置 **10 秒** 总超时；服务端回写 md5 后设置 **5 秒** 读超时，防止半开连接堆积。
- core version 必须严格相等（`version.GetVersion()`），完整版本仅用于展示；因此 0.26.x 客户端只能连 0.26.0 core 的服务端。
- vkey 不直接上送，服务端用 `common.Getverifyval(v.Key)=md5(vkey)` 比对，见 [db.go#L76-L92](lib/file/db.go#L76-L92)。这是静态共享密钥，没有随机 challenge。
- 主连接对 TCP 设置 `SetKeepAlive(true)` + 5 秒周期，KCP 则由 mux 的 disconnect_timeout 保活。
- `conf` 连接额外要求 `ConfigConnAllow=true`，公共客户端（public_vkey）例外。

### 1.3 帧格式

**长度前缀帧**（任务、Link、健康、状态查询）：

```
+----------------+----------------------+
| len (4, LE)    | payload (len bytes)  |
+----------------+----------------------+
```

- `SendInfo(t, flag)`：可选 4 字节 flag（`task`/`host`/`conf`）+ 长度 + JSON，见 [conn.go#L231-L254](lib/conn/conn.go#L231-L254)。
- `GetLinkInfo`：长度 + `Link` JSON。
- `GetTaskInfo/GetHostInfo/GetConfigInfo`：长度 + 对应 JSON，并补 `Id`、`Flow`、`NoStore` 等运行期字段。
- 单帧上限 64KB（`PoolSize`），部分读取路径（`GetShortLenContent`）额外限制 32KB。

**固定 4 字节标记帧**：`ReadFlag` 读取 4 字节并转字符串，用于多路控制连接上的 `stus`/`conf`/`host`/`task` 指令，以及 `clse`。

**健康帧**：长度 + `target*#*status*#*`，由 `BinaryWrite` 拼接，见 [util.go#L174-L193](lib/common/util.go#L174-L193)。

**新增结果帧**：1 字节 bool（`WriteAddOk/WriteAddFail`）。配置文件模式下，`conf` 新增成功还会追加 16 字节随机 vkey。

**数据帧**：Link 下发完成后不再有任何帧头，子流内就是原始 TCP/UDP 字节。

### 1.4 Link 结构

定义在 [link.go](lib/conn/link.go#L17-L47)：

```go
type Link struct {
    ConnType   string  // "tcp" / "http" / "udp" / "udp5"
    Host       string  // 目标地址
    Crypt      bool    // TLS
    Compress   bool    // snappy
    LocalProxy bool    // 服务端本地直连，不经过 npc
    RemoteAddr string  // 用户源地址，用于日志/鉴权上下文
    Option     Options // Timeout，默认 5s
}
```

客户端收到后：

1. `common.FormatAddress`：只有端口时补 `127.0.0.1:`。
2. `ConnType=="http"` 走特殊分支：逐条读取 HTTP 请求并转发，保持 keep-alive。
3. `ConnType=="udp5"` 走 SOCKS5 UDP 中继分支。
4. 其余按 `ConnType` 直接 `net.DialTimeout`，然后把子流与目标连接双向复制。

### 1.5 配置文件模式（conf 协议）

入口 [StartFromFile](client/control.go#L90-L181)，服务端 [getConfig](bridge/bridge.go#L391-L536)。

流程：

1. 客户端用 vkey 建 `conf` 连接，服务端回 1 字节 `isPub`。
2. 若为公共客户端：客户端发 `conf` + `Client` JSON；服务端 `NewClient`，vkey 为空则生成 16 位随机值，回 `true` + 16 字节 vkey。客户端写入临时目录 `npc_vkey.txt`，后续都用这个 vkey。
3. 客户端逐条发 `host` + `Host` JSON；服务端查重、设置默认 `Location="/"`，回 1 字节结果。
4. 客户端逐条发 `task` + `Tunnel` JSON；服务端展开 `Ports` 端口段与 `TargetStr` 目标列表，按端口拆成多个任务；每个任务检查 `HasTunnel`、端口可用性，然后投递到 `Bridge.OpenTask`，由 `DealBridgeTask` 启动，见 [server.go#L53-L85](server/server.go#L53-L85)。
5. `file` 模式的任务在客户端额外启动本地文件服务器；`secret`/`p2p` 额外启动本地监听。
6. 连接关闭后客户端进入 `NewRPClient` 主循环；配置文件自动重连由 `auto_reconnection` 控制，间隔 5 秒。

状态查询：

- 客户端发 `stus` + `md5(当前 vkey)`，服务端返回长度前缀的 remark 列表，以 `*#*` 分隔；客户端逐项打印 `ok` / `not running`。
- 服务端按 client 过滤 Hosts 与正在运行（RunList）的 Tasks。

### 1.6 健康检查协议

客户端 [health.go](client/health.go#L20-L102)：

- 用 `container/heap` 维护下次检查时间，支持 tcp dial 或 http GET；失败计数达到 `HealthMaxFail` 时上报 `0`，恢复时上报 `1`。
- 健康信息走主连接，帧为 `target*#*status*#*`，状态位是字符串 `"0"/"1"`。

服务端 [GetHealthFromClient](bridge/bridge.go#L93-L155)：

- `status=false`（即 `0`）→ 把 target 从 `TargetArr` 移到 `HealthRemoveArr`，后续 `GetRandomTarget` 不再选中。
- `status=true`（即 `1`）→ 从 `HealthRemoveArr` 恢复到 `TargetArr`。
- 读取循环报错即 `DelClient(id)`，整条客户端下线。

注意：健康信息只对 `Mode=="tcp"` 的 Tunnel 和对所有 Host 生效；UDP 任务不参与摘除。

### 1.7 数据面：加密、压缩、限速

包装顺序在 [GetConn](lib/conn/conn.go#L407-L417)：

```
raw net.Conn
  └─ crypt ? TLS
  └─ compress ? snappy
  └─ rate limit (token bucket)
```

- **Crypt 不是自研加密，而是自签 TLS**：启动时 `crypt.InitTls()` 现场生成 2048 位 RSA 自签证书（10 年有效），服务端 `tls.Server`，客户端 `InsecureSkipVerify=true`，见 [tls.go](lib/crypt/tls.go#L23-L49)。因此只提供传输加密，不提供服务端身份校验。
- **Compress 是 snappy 流式压缩**，每次 Write 后立即 Flush，见 [snappy.go](lib/conn/snappy.go#L25-L38)；与 TLS 互斥，开了 Crypt 就不会走 snappy 分支。
- **限速是客户端级令牌桶**，`RateLimit` 单位 KB/s，桶容量 2 倍速率；`Get` 不足时每 100ms 轮询等待，见 [rate.go](lib/rate/rate.go#L16-L79)。默认速率 `2<<23` B/s（约 16MB/s）。
- **流量统计**：`CopyWaitGroup` 用 ants 协程池跑两个方向的 `io.Copy`，结束后把 in/out 累加到 Tunnel/Host 的 Flow；服务端 `flow_store_interval` 定时落盘，见 [pool.go](lib/goroutine/pool.go#L59-L75) 与 [server.go#L449-L459](server/server.go#L449-L459)。

### 1.8 UDP 数据封装

两条不同路径：

1. **普通 UDP 隧道（mode=udp）**：服务端为每个来访 `ip:port` 建立一条 mux 子流，子流内直接传裸 UDP payload；`addrMap` 记录映射，读超时 10 分钟，见 [udp.go#L55-L95](server/proxy/udp.go#L55-L95)。客户端把子流 `Dial("udp", target)` 后双向复制。
2. **SOCKS5 UDP ASSOCIATE（ConnType=udp5）**：
   - 服务端 relay 收到的是完整 SOCKS5 UDP 报文（RSV/FRAG/ATYP/ADDR/PORT + DATA），原样写进子流；
   - 客户端按 `common.ReadUDPDatagram` 解析，去掉头后发给内网；
   - 反向由客户端加 SOCKS5 头，再以 `int32 LE 长度` 前缀写回；
   - 服务端按长度读，再 `WriteTo` 给访问者。
   - 头部编码在 [netpackager.go](lib/common/netpackager.go#L24-L204)；`Rsv` 在 UDP-over-TCP 扩展里复用为数据长度。

### 1.9 P2P 与 Secret

**Secret**（服务端不经手监听端口）：

- 客户端本地监听端口被访问 → 新建 `sert` 连接 → 写 `md5(password)` 32 字节 → 服务端按 `md5(password)` 匹配任务 → `DealClient` 直接连 target。
- 密码在任务间必须唯一（`NewTask` 会查重）。

**P2P**：

1. 客户端 `p2pm` 连接，写 `md5(password)`；服务端找到任务，向该任务所属客户端的 `signal` 连接写 `udpc` + 长度前缀的服务端 p2p 地址 + 密码。
2. provider 客户端收到 `udpc` 后，在本机 UDP socket 上做 KCP `ServeConn`，等待 visitor。
3. visitor 同时拿到服务端 p2p 地址，通过服务端 UDP 端口（`p2p_port`、+1、+2 三个实例）交换双方公网地址；20 秒内互相通知。
4. visitor 用 KCP 直连 provider 公网地址，建立 mux；`p2pBridge` 通过该 mux 下发 Link，provider 侧 `handleChan` 连 target。
5. 打洞失败（`udpConn==nil`）自动回退到 `secret` 服务器中继。
6. P2P 只支持 compress，不支持 TLS（源码 TODO：客户端包内没有证书文件）。

相关代码：[p2p.go](server/proxy/p2p.go#L12-L80)、[local.go](client/local.go#L128-L219)、[client.go#L88-L152](client/client.go#L88-L152)。

### 1.10 端口复用 pmux

仅当 `bridge_port` 与 `web_port`、`http_proxy_port`、`https_proxy_port` 任意一个相同才启用，见 [connection.go](server/connection/connection.go#L19-L33)。

识别方式（[pmux.go](lib/pmux/pmux.go#L82-L138)）：

- 先读 3 字节，按 `BytesToNum`（把每个字节的十进制字符串拼接）转整数；
- `GET/POST/HEAD/PUT/DELETE/CONNECT/OPTIONS/TRACE` 的 3 字节编码落入 HTTP 分支，再读 `Host` 头：等于 `web_host` 走管理端，否则走 HTTP 反代；
- `TST` 的编码是 `848384`，即 `CLIENT` 分支，走 bridge；
- 其余首包（典型 TLS ClientHello `0x16 0x03 ...`）归为 HTTPS 分支；
- 每个分支等待 10 秒，超时丢弃连接。

这也是为什么客户端握手第一步必须发裸 `TST`：它同时承担了 pmux 分流标识。

---

## 2. 代理实现

### 2.1 模式分发表

[server.NewMode](server/server.go#L126-L157)：

| Mode | 实现 | 监听 | 说明 |
|---|---|---|---|
| `tcp` / `file` | `TunnelModeServer` + `ProcessTunnel` | 任务端口 | 普通端口转发；file 复用该监听 |
| `httpProxy` | `TunnelModeServer` + `ProcessHttp` | 任务端口 | HTTP 代理（含 CONNECT） |
| `socks5` | `Sock5ModeServer` | 任务端口 | SOCKS5 CONNECT / UDP ASSOCIATE |
| `udp` | `UdpModeServer` | UDP 任务端口 | 每源地址一条隧道 |
| `webServer` | `WebServer` | web_port | 启动 Beego 管理端 |
| `httpHostServer` | `httpServer` + `HttpsServer` | http_proxy_port / https_proxy_port | 域名/SNI 反代 |
| `tcpTrans` | `TunnelModeServer` + `HandleTrans` | 任务端口 | Linux iptables 透明代理 |
| `secret` / `p2p` | 无监听 | - | 仅登记 RunList，靠客户端本地监听 + 服务端匹配 |

### 2.2 TCP 端口转发

[TunnelModeServer.Start](server/proxy/tcp.go#L35-L46)：

1. 监听 `ServerIp:Port`；
2. 每连接先 `CheckFlowAndConnNum`（流量上限 + 最大连接数，`GetConn` 原子自增）；
3. `ProcessTunnel` 从 `Target.GetRandomTarget` 选目标（多目标轮询，离线列表跳过）；
4. `DealClient` 构造 Link，通过 bridge 申请子流，成功回调后 `CopyWaitGroup` 双向复制，结束后 `AddConn` 归还计数。

`LocalProxy=true` 时，服务端直接 `net.Dial` 目标，不经过 npc，见 [bridge.go#L314-L319](bridge/bridge.go#L314-L319)。

### 2.3 UDP 转发

[udp.go](server/proxy/udp.go#L31-L95)：

- 单一 UDP socket 接所有来访数据报；
- 以 `remoteAddr.String()` 为 key 查 `addrMap`：
  - 命中：直接写隧道；
  - 未命中：建 Link（类型 `udp`）→ 申请子流 → 包装 crypt/compress → 存 map → 先写首包 → 循环读回包写回用户；
- 回包循环设置 10 分钟读超时，超时后释放映射；
- 流量按入/出累加到任务 Flow。

### 2.4 SOCKS5

[socks5.go](server/proxy/socks5.go#L49-L394)：

1. 版本协商：`05` + 方法数；需要认证时回 `05 02`，否则 `05 00`。
2. 用户名/密码子协商（RFC 1929）：支持 `Client.Cnf.U/P` 或任务级 `MultiAccount.AccountMap`。
3. 请求：读取 `VER/CMD/RSV/ATYP/DST.ADDR/DST.PORT`。
   - `CONNECT`：解析 IPv4/IPv6/域名，`DealClient` 建立隧道，成功回调里回 `05 00 ...` 成功应答；
   - `UDP ASSOCIATE`：在服务端 IP 上随机 UDP 端口建立 relay，回实际 relay 地址；Link 类型 `udp5`，两个 goroutine 分别转发访问者→隧道、隧道→访问者；
   - `BIND`：**未实现**，只 return，不回应答，连接会悬挂到超时/关闭。
4. 应答中的 BND.ADDR/BND.PORT 用服务端本地地址填充。

### 2.5 HTTP 代理（httpProxy）

[ProcessHttp](server/proxy/tcp.go#L110-L125) + [GetHost](lib/conn/conn.go#L61-L92)：

- 从连接读取到 `\r\n\r\n` 为止（最多 32KB），用 `http.ReadRequest` 解析，拿到 Host 与方法。
- Host 无端口时补 `:80`；`r.URL.Opaque=="443"` 或形如 `host:443` 时补 `:443`。
- `CONNECT`：先回 `HTTP/1.1 200 Connection established`，之后按裸 TCP 转发。
- 普通代理请求：把已预读的 `rb` 原样写进隧道，随后双向复制；不逐请求解析、不改 Host。
- 可选 Basic Auth（`Client.Cnf.U/P`），失败回 401 并关闭。

### 2.6 域名代理（httpHostServer）与 HTTPS

**HTTP 路由**：

- 监听 `http_proxy_port`，Beego 无关，直接 `http.Server` + `Hijack` 后自行处理，见 [http.go#L57-L115](server/proxy/http.go#L57-L115)。
- Host 匹配在 [db.go#L319-L360](lib/file/db.go#L319-L360)：
  - 去掉端口；
  - `Scheme` 必须为 `all` 或与请求 scheme 一致；
  - 支持 `*` 通配（实现是删掉 `*` 后做子串匹配）；
  - `Location` 取最长前缀匹配，默认 `/`。
- 命中后：流量/连接数检查 → Basic Auth（可选）→ `GetRandomTarget` → Link 类型 `http` → 下发。
- 请求/响应循环：
  - 改写 Host、Header，追加 `X-Forwarded-For` 和 `X-Real-IP`（`http_add_origin_header`）；
  - 支持 keep-alive 和多请求复用同一条隧道；
  - Host 变化时关闭旧隧道并 `goto reset` 重新建链；
  - `resetReqMethod` 修复首字符被截断的 `ET`/`OST`（源码注释："Why one character less???"）。
- 缓存（`http_cache=true`）：
  - 仅当 URL Path 含 `.` 时缓存；
  - 每次响应 `httputil.DumpResponse` 后写入 LRU，key 为 `host + path`；
  - 命中缓存直接回写，不设 TTL，也不看 `Cache-Control`，可能返回陈旧内容；
  - 实现见 [lru.go](lib/cache/lru.go)。

**HTTPS 两种模式**：

1. `https_just_proxy=true`（默认）：不做 TLS 终止，只读 ClientHello 的 SNI，按域名找 Host，然后把原始 TLS 字节（含 ClientHello）通过隧道转给内网目标。
2. 本地终止：
   - 按 SNI 动态加载 Host 的证书/私钥，建立独立 `HttpsListener`，缓存在 `sync.Map`；
   - SNI 为空或证书缺失时回落到 `https_default_cert_file/key_file`；
   - 找不到域名则直接关闭；
   - 终止后走与 HTTP 相同的请求/响应处理；
   - 代理服务器显式设置 `TLSNextProto: empty` 关闭 HTTP/2，因此只支持 HTTP/1.1。

SNI 解析：[GetServerNameFromClientHello](server/proxy/https.go#L161-L175)，读取最多 4096 字节，跳过 5 字节 TLS 记录头，用 `crypt.ClientHelloMsg` 解析扩展。

### 2.7 文件服务

- 任务 Mode=`file`，服务端仍用 `TunnelModeServer` 监听任务端口。
- 客户端 `startLocalFileServer` 单独建一条 `file` 连接并创建 mux，mux 的每个 stream 直接交给 `http.Server`，内容为 `http.FileServer(http.Dir(LocalPath))` + `StripPrefix`，见 [local.go#L65-L78](client/local.go#L65-L78)。
- 服务端 `SendLinkInfo` 对 `file` 任务特殊处理：只返回 mux 子流，不发送 Link，并强制关闭 Crypt/Compress，见 [bridge.go#L332-L350](bridge/bridge.go#L332-L350)。
- 因此文件流量明文、无压缩，且每个 HTTP 连接都是一个 mux stream。

### 2.8 Secret / P2P

见 1.9。服务端对这两类任务只做"登记 + 密码匹配 + 中继"，不占用监听端口；`AddTask` 对它们直接写 RunList 后返回，不做端口检测。

### 2.9 透明代理 tcpTrans

- Linux：通过 iptables REDIRECT 把流量导到任务端口，再用 `SO_ORIGINAL_DST` 取原始目的地址，见 [transport.go](server/proxy/transport.go#L14-L44)。
- Windows：空实现，见 [transport_windows.go](server/proxy/transport_windows.go)。

### 2.10 资源与权限治理

| 机制 | 实现位置 | 说明 |
|---|---|---|
| 最大连接数 | `Client.GetConn/AddConn` | 原子计数；UDP 会话在回包循环结束时归还 |
| 流量上限 | `CheckFlowAndConnNum` | `FlowLimit` 单位 MB，比较时 `<<20` |
| 速率限制 | `Rate` 令牌桶 | 客户端级，KB/s |
| 端口白名单 | `tool.InitAllowPort` + `TestServerPort` | `allow_ports` 支持区间/逗号；p2p/secret 跳过 |
| 最大隧道数 | `MaxTunnelNum` | Web 添加隧道时校验 |
| IP 白名单 | `Bridge.Register` + `SendLinkInfo` | `ip_limit=true` 时校验注册 IP 及有效期；管理员 Web 登录自动注册本机 IP 2 小时 |
| 连接数超限 | `CheckFlowAndConnNum` | 超限直接关闭用户连接 |

---

## 3. Web API

### 3.1 两种鉴权方式

实现：[base.go#L24-L63](web/controllers/base.go#L24-L63)、[login.go](web/controllers/login.go)。

**A. Session 登录**

- `web_username` / `web_password` 匹配 → `isAdmin=true`。
- `allow_user_login=true` 且有客户端 `WebUserName/WebPassword` 匹配 → 普通用户，绑定 `clientId`。
- 若客户端未配置 Web 账号，也接受固定用户名 `user` + 该客户端 vkey 作为密码。
- 若 `web_username`/`web_password` 都为空，访问 `/login/index` 会自动隐式登录（无鉴权模式）。
- 失败限流：同一 IP 60 秒内最多 10 次失败（非显式登录失败不计）。

**B. auth_key 签名**

每个请求附加：

```
auth_key = md5(配置文件 auth_key + timestamp)
timestamp = 当前 Unix 秒
```

- 时间窗 ±20 秒；
- 校验通过会 `SetSession("isAdmin", true)`，即 **API 签名等价于管理员会话**；
- `auth_crypt_key` 必须为 16 字节，用于 `/auth/getauthkey` 的 AES-CBC 加密返回。

### 3.2 路由规则

[routers.go](web/routers/router.go#L8-L25) 使用 Beego AutoRouter：

- Controller 名去掉 `Controller` 后小写作为一级路径：`IndexController` → `/index`；
- Action 名小写作为二级路径：`GetTunnel` → `/index/gettunnel`；
- `web_base_url` 非空时整体挂到该子路径命名空间；
- GET/POST 都映射到同一 Action，控制器内部按 `Request.Method` 分支。

### 3.3 响应约定

| 场景 | 结构 |
|---|---|
| 普通操作 | `{"status": 0/1, "msg": "..."}` |
| 列表 | `{"rows": [...], "total": n, ...}`；列表接口还会附带 `ip`、`bridgeType`、`bridgePort` |
| 单条详情 | `{"code": 0/1, "data": {...}}` |
| 页面 | HTML（GET 时渲染模板） |

### 3.4 端点清单

#### Auth

| 方法 | 路径 | 参数 | 说明 |
|---|---|---|---|
| POST | `/auth/gettime` | - | 返回服务端 Unix 时间，供校准 |
| POST | `/auth/getauthkey` | - | 返回 `{status, crypt_auth_key, crypt_type}`；AES-CBC/PKCS5，key=iv=`auth_crypt_key`，hex 编码 |

#### Login

| 方法 | 路径 | 参数 | 说明 |
|---|---|---|---|
| GET | `/login/index` | - | 登录页；空账号密码时自动登录 |
| POST | `/login/verify` | `username`,`password` | 登录，返回 `{status,msg}` |
| GET/POST | `/login/register` | GET 页面；POST `username`,`password` | 需 `allow_user_register=true`，注册客户端账号 |
| GET | `/login/out` | - | 退出，清 session |

#### Client

| 方法 | 路径 | 参数 | 说明 |
|---|---|---|---|
| GET | `/client/list` | - | 客户端列表页 |
| POST | `/client/list` | `search`,`sort`,`order`,`offset`,`limit` | 列表数据；`sort` 字段名、`order` 升降序 |
| POST | `/client/getclient` | `id` | 单客户端详情 |
| GET/POST | `/client/add` | GET 页面；POST `remark`,`vkey`,`u`,`p`,`compress`,`crypt`,`config_conn_allow`,`rate_limit`,`flow_limit`,`max_conn`,`max_tunnel`,`web_username`,`web_password` | 新增客户端，vkey 为空自动生成 16 位 |
| GET/POST | `/client/edit` | 同上 + `id` | 修改；非管理员只能改自己的备注/认证/压缩/Web 密码，vkey 与限额仅管理员可改 |
| POST | `/client/changestatus` | `id`,`status` | 启停客户端；停用会断开在线连接 |
| POST | `/client/del` | `id` | 删除客户端，同时删除其隧道/Host 并断开连接 |

#### Index（隧道 / Host / 页面）

| 方法 | 路径 | 参数 | 说明 |
|---|---|---|---|
| GET | `/index/index` | - | 仪表盘 |
| GET | `/index/help` | - | 关于页 |
| GET | `/index/tcp`、`/udp`、`/socks5`、`/http`、`/file`、`/secret`、`/p2p`、`/host`、`/all` | - | 各类型列表页 |
| POST | `/index/gettunnel` | `type`,`client_id`,`search`,`offset`,`limit` | 隧道列表 |
| POST | `/index/getonetunnel` | `id` | 单条隧道 `{code,data}` |
| GET/POST | `/index/add` | GET 页面；POST `type`,`port`,`server_ip`,`target`,`client_id`,`remark`,`password`,`local_path`,`strip_pre`,`local_proxy` | 新增隧道；校验端口、最大隧道数 |
| GET/POST | `/index/edit` | 同上 + `id` | 修改后自动 Stop + Start |
| POST | `/index/stop` / `start` / `del` | `id` | 停止 / 启动 / 删除隧道 |
| GET/POST | `/index/hostlist` | GET 页面；POST `client_id`,`search`,`offset`,`limit` | 域名列表 |
| POST | `/index/gethost` | `id` | 单条域名 `{code,data}` |
| GET/POST | `/index/addhost` | `host`,`target`,`header`,`hostchange`,`remark`,`location`,`scheme`,`key_file_path`,`cert_file_path`,`client_id`,`local_proxy` | 新增域名规则 |
| GET/POST | `/index/edithost` | 同上 + `id` | 修改域名规则 |
| POST | `/index/delhost` | `id` | 删除域名规则 |

### 3.5 权限模型

- `isAdmin=true`：全部接口可用。
- `isAdmin=false`（客户端账号）：
  - `/client/*`：`add` 被禁止；带 `id` 时必须等于 session 中的 `clientId`；
  - `/index/*`：带 `id` 时校验该 Tunnel/Host 是否属于该 client；不带 id 的列表接口通过 `client_id` 过滤（页面侧限制）。
  - 仅靠 `CheckUserAuth` 的 id 归属校验，属于"防越权"而非完整 RBAC。
- `auth_key` 调用方直接获得管理员 session，等同全权限 API Key。

### 3.6 调用示例

```bash
timestamp=$(date +%s)
auth_key=$(printf '%s%s' "$NPS_AUTH_KEY" "$timestamp" | md5sum | awk '{print $1}')

curl -s -X POST "http://127.0.0.1:8080/index/gettunnel" \
  -d "auth_key=$auth_key" \
  -d "timestamp=$timestamp" \
  -d "type=tcp&client_id=1&offset=0&limit=10"
```

获取 auth key（需 `auth_crypt_key` 为 16 位）：

```bash
curl -s -X POST http://127.0.0.1:8080/auth/getauthkey
# {"status":1,"crypt_auth_key":"<hex>","crypt_type":"aes cbc"}
```

解密要点：AES-128-CBC，key 与 IV 均为 `auth_crypt_key`，PKCS5 padding，密文为 hex。

### 3.7 文档与实现差异

- `docs/webapi.md` 未覆盖 `/client/changestatus`、`/index/gethost`、`/auth/gettime`、`/auth/getauthkey` 之外的登录/注册接口；
- 文档写 `auth_key`、`timestamp` 放 POST body，当前实现两者都通过 Beego `Input.Query` 读取（会合并 query/form），因此 body 可用；
- 所有路径需加 `web_base_url` 前缀（若配置）；
- AutoRouter 的方法名大小写不敏感，实际 URL 使用全小写。

---

## 4. 观察、风险与待办

### 4.1 安全

1. **crypt 只加密不认证**：自签证书 + 客户端跳过校验，可被中间人替换；生产环境建议外层走 TLS/VPN，或改造为双向证书。
2. **vkey 是静态共享密钥**：上送 `md5(vkey)`，无 challenge/nonce，抓包可重放；默认 `public_vkey=123`、`admin/123` 必须修改。
3. **auth_key 等于管理员凭证**：签名通过即授予 admin session；20 秒窗口内可重放；务必只在 HTTPS 下暴露 Web。
4. **敏感信息明文落盘/打印**：`clients.json` 存明文 vkey、Web 密码；配置文件模式启动日志会打印 vkey 作为 Web 密码。
5. **Basic Auth 账号密码明文配置**：`Client.Cnf.U/P` 明文存在 `clients.json`；配置文件模式下 `Client` JSON 还会经**未加密**的 `conf` 连接上送。
6. **Web 会话默认 Beego session**，未看到 CSRF token；Web 管理端应限制来源 IP。

### 4.2 稳定性 / 性能

1. **每连接一个 goroutine + ants 池**：复制池上限 20 万，mux 池 10 万；高并发时主要是 fd 与内存瓶颈。
2. **UDP addrMap 按外部源地址增长**：10 分钟 idle 才释放，NAT 场景下源端口多会显著放大映射与隧道数。
3. **HTTP 缓存无 TTL/无协议头处理**：仅按 `host+path` 命中，可能返回过期响应；且只缓存 path 含 `.` 的响应，行为不直观。
4. **HTTPS 代理关闭 HTTP/2**：`TLSNextProto` 置空，只能 HTTP/1.1。
5. **SOCKS5 BIND 未实现**：连接会悬挂，建议直接回 `command not supported`（当前走了 default 分支才回，`BIND` 分支没有）。
6. **端口复用是启发式**：非 HTTP/TLS/TST 首包会落入 HTTPS 分支，若与 HTTPS 端口复用可能误判。
7. **全局单例较多**：`file.Db`、`server.Bridge`、`server.RunList`、`db.JsonDb`；跨模块共享，锁粒度粗，扩展时需注意。
8. **版本硬校验 core 0.26.0**：升级必须成对升级，灰度不友好。

### 4.3 设计亮点

- 控制面/数据面分离，数据隧道用 mux 复用，避免每连接重新握手。
- Link 只下发一次 JSON，之后完全透明转发，协议开销极低。
- 配置模式把 Web 端配置和 npc 本地配置统一成同一套 `Tunnel/Host` 结构，便于双向同步。
- 健康检查在客户端侧执行，服务端只维护可用目标列表，职责清晰。
- pmux 用首包特征实现单端口同时承载 bridge/Web/HTTP/HTTPS，部署友好。

---

## 5. 下一步可深挖

- `ehang.io/nps-mux` 的具体复用实现（窗口、流控、断线检测）。
- Web 前端 JS 与各接口的字段映射（尤其是 `sort/order`、`local_proxy`）。
- JSON 持久化的分隔格式、并发写入与损坏恢复。
- 安装/守护进程（`cmd/nps`、`cmd/npc` 的 install/start/stop）与 Docker 构建。
- 压测：单客户端多隧道、UDP 高 PPS、HTTP keep-alive 场景下的 fd/内存曲线。

---

## 6. 本次阅读清单

协议与基础库：

- `lib/conn/conn.go`、`lib/conn/link.go`、`lib/conn/snappy.go`、`lib/conn/listener.go`
- `lib/common/const.go`、`lib/common/util.go`、`lib/common/netpackager.go`、`lib/common/pool.go`
- `lib/crypt/crypt.go`、`lib/crypt/tls.go`
- `lib/pmux/pmux.go`、`lib/rate/rate.go`、`lib/rate/conn.go`
- `lib/goroutine/pool.go`、`lib/cache/lru.go`

服务端：

- `bridge/bridge.go`
- `server/server.go`、`server/connection/connection.go`
- `server/proxy/base.go`、`tcp.go`、`udp.go`、`socks5.go`、`http.go`、`https.go`、`p2p.go`、`transport.go`、`transport_windows.go`
- `server/tool/utils.go`

客户端：

- `client/client.go`、`client/control.go`、`client/local.go`、`client/health.go`、`client/register.go`
- `lib/config/config.go`

Web：

- `web/routers/router.go`
- `web/controllers/base.go`、`login.go`、`client.go`、`index.go`、`auth.go`
- `docs/api.md`、`docs/webapi.md`

---

# 第二部分：nps 服务端深入解析

> 日期：2026-10-27（第二轮）
> 范围：`cmd/nps`、`server/`、`bridge/`、`web/controllers/`、`lib/file`、`lib/pmux`、`lib/rate`
> 视角：只看 **服务端**——一条外部连接是如何被接住、分发、转成内网请求的

## 7. 服务端总体骨架

一句话：**nps 服务端是一个「按端口 / 域名 / 首包特征」分流的连接工厂**，本身不保存业务数据，所有内网请求最终都被拆成一条 mux 子流交给 npc。

### 7.1 启动链路

```
main()
 ├─ beego.LoadAppConfig("ini", runPath/conf/nps.conf)
 ├─ common.InitPProfFromFile()
 ├─ 日志初始化（log_level=7, log_path, CallDepth=3）
 ├─ kardianos/service 配置（Name=Nps；Arguments 追加 "service"）
 ├─ 子命令分流：reload / install / start / restart / stop / uninstall / update
 └─ s.Run()
      └─ nps.run()  ← recover 兜底，panic 打印 64KB 栈
           └─ run()
                ├─ routers.Init()                 // 注册 Beego 路由
                ├─ 构造伪任务 &file.Tunnel{Mode:"webServer"}
                ├─ 读 bridge_port（失败 os.Exit(0)）
                ├─ connection.InitConnectionService()   // 端口复用初始化
                ├─ crypt.InitTls()                // 生成自签证书
                ├─ tool.InitAllowPort()           // 解析 allow_ports
                ├─ tool.StartSystemInfo()         // 系统监控采集
                ├─ disconnect_timeout（默认 60）
                └─ go server.StartNewServer(...)
```

要点：

| 观察 | 说明 |
|---|---|
| `run()` 末尾没有 `select{}` | 常驻靠外层 `nps.run()` 的 `select { case <-p.exit }`；`StartNewServer` 本身在 goroutine 中 |
| `server.Bridge` 是包级全局变量 | Web 控制器、各 proxy 服务直接引用，没有依赖注入，进程内单例 |
| `webServer` 是一个「假任务」 | 它不监听业务端口，只作为 `NewMode` 的入口，用来同时拉起控制台和 HTTP/HTTPS 反代 |
| systemv 特殊处理 | `install` 会额外建 `/etc/rc.d/S90`、`K02` 软链；`start/stop` 走 `/etc/init.d/Nps` |
| Windows 差异 | 日志路径转义、systemd/sysv 依赖跳过，其余流程一致 |

### 7.2 StartNewServer：服务端对象图

`StartNewServer(bridgePort, cnf *file.Tunnel, bridgeType, bridgeDisconnect)` 做五件事（[server.go#L88-L112](server/server.go#L88-L112)）：

1. `Bridge = bridge.NewTunnel(bridgePort, bridgeType, ip_limit, RunList, bridgeDisconnect)`
2. `go Bridge.StartTunnel()`，失败直接 `os.Exit(0)`
3. `p2p_port` 存在时，启动 `p / p+1 / p+2` **三个** P2P 地址交换服务
4. `go DealBridgeTask()` + `go dealClientFlow()`
5. `NewMode(Bridge, cnf)` 构造主服务并 `Start()`，成功则 `RunList.Store(cnf.Id, svr)`

进程内的三张全局表：

| 结构 | 类型 | 内容 |
|---|---|---|
| `Bridge.Client` | `sync.Map` | clientId → `*bridge.Client`（signal + tunnel + file） |
| `RunList` | `sync.Map` | taskId → `proxy.Service`；secret/p2p 任务存 `nil` 占位 |
| `file.GetDb().JsonDb` | `sync.Map ×3` | Clients / Tasks / Hosts，配 JSON 落盘 |

`dealClientFlow()` 每分钟调用一次 `dealClientData()`：把每个 client 的 Hosts 与 Tasks 流量**清零后重新求和**，避免重复累加（[server.go#L283-L313](server/server.go#L283-L313)）。

### 7.3 NewMode 分发表

| Mode | 构造 | 监听 / 行为 |
|---|---|---|
| `tcp` / `file` | `NewTunnelModeServer(ProcessTunnel)` | 裸 TCP 转发；`file` 走独立 mux 且强制关闭加密压缩 |
| `httpProxy` | `NewTunnelModeServer(ProcessHttp)` | HTTP 正向代理 + CONNECT |
| `socks5` | `NewSock5ModeServer` | RFC1928/1929，支持 CONNECT 与 UDP ASSOCIATE |
| `udp` | `NewUdpModeServer` | 单端口 UDP，多会话按来源地址复用 |
| `tcpTrans` | `NewTunnelModeServer(HandleTrans)` | 透明代理，Linux 取 `SO_ORIGINAL_DST` |
| `webServer` | `InitFromCsv()` + 造 `httpHostServer` 任务 + `NewWebServer` | 控制台（Beego）+ 触发 80/443 反代 |
| `httpHostServer` | `NewHttp(...)` | 读 `http_proxy_port` / `https_proxy_port` / 缓存 / `X-Forwarded-*` |

`webServer` 分支的 `Port:0, Mode:"httpHostServer"` 任务没有真实监听端口，`AddTask` 中对 `httpHostServer` 特意跳过 `TestServerPort`。

## 8. Bridge 生命周期

### 8.1 监听与握手

- `StartTunnel`：先 `go s.ping()`；`bridge_type=kcp` 走 `conn.NewKcpListenerAndProcess`，否则 `connection.GetBridgeListener(tunnelType)` + `conn.Accept`。
- `cliProcess` 的 7 步握手与第 1.2 节一致；服务端读取流程：

```
GetShortContent(3)        → 不校验内容（应为 TST）
GetShortLenContent()      → 与 version.GetVersion() 比对
GetShortLenContent()      → 完整版本（记录到 vs）
Write(md5(core version))  → 32 字节
SetReadDeadlineBySecond(5)
GetShortContent(32)       → vkey
  ├─ 失败：写 "vkey"
  └─ 成功：写 "sucs"，得到 clientId
ReadFlag()                → 4 字节连接类型
typeDeal(...)
SetAlive(tunnelType)
```

### 8.2 typeDeal：七种连接类型

| 标记 | 关键逻辑 |
|---|---|
| `main` | pub client 直接关闭；TCP 设 keepalive 5s；`LoadOrStore` 若已存在旧 signal 则先 `WriteClose()` 再替换；`go GetHealthFromClient(id, c)` |
| `chan` | 在连接上 `nps_mux.NewMux(c.Conn, tunnelType, disconnectTime)`，复用已有 Client 结构 |
| `conf` | `!isPub && !ConfigConnAllow` 直接关；写 `isPub`（bool）后 `go getConfig` |
| `rgst` | `go register`：读 `int32` 小时数，写 `Bridge.Register[ip] = now + hour` |
| `sert` | 读 32 字节 md5(password)，投递 `SecretChan`，由 `DealBridgeTask` 匹配任务后中继 |
| `file` | 另建一条独立 mux，仅用于文件服务 |
| `p2pm` | 读 32 字节 md5(password) 找任务；向 provider 的 signal 写 `udpc` + 服务端 UDP 地址 + 密钥；向请求者只写地址 |

### 8.3 在线判定与清理

`ping()` 每 5 秒扫描 `Bridge.Client`（[bridge.go#L361-L388](bridge/bridge.go#L361-L388)）：

- `tunnel == nil || signal == nil` → `retryTime++`，累计 ≥3 判定掉线
- `tunnel.IsClose` → 立即判定掉线
- 掉线统一走 `DelClient(id)`：

```
signal.Close() → Client.Delete(id)
             → 非 public_vkey 客户端：CloseClient <- id
                 → DealBridgeTask: DelTunnelAndHostByClientId(id, true)
                                    （只删 NoStore 的 host/task）
                 → 若 Client.NoStore，再 DelClient
```

这意味着：**配置文件模式下下发的临时任务，在客户端断开时会被自动回收**；Web 面板手工创建的任务则保留。

### 8.4 SendLinkInfo：数据面的唯一出口

`SendLinkInfo(clientId, link, t)`（[bridge.go#L314-L359](bridge/bridge.go#L314-L359)）是所有代理模式共用的「借子流」函数：

```
link.LocalProxy ──true──> net.Dial("tcp", link.Host)   // 服务端本机直接拨号
                └─false─> Bridge.Client[clientId]
                            ├─ ip_limit 校验 Bridge.Register
                            ├─ file 模式取 Client.file，且 Crypt/Compress=false
                            ├─ tunnel.NewConn()            // 一条 mux 子流
                            └─ conn.NewConn(target).SendInfo(link, "")
```

注意 `file` 模式虽然也返回子流，但**不发 Link**——npc 侧直接把它当 HTTP 服务器连接使用，所以服务端必须在发 Link 前把 Crypt/Compress 置 false 并提前 return。

## 9. 配置下发 getConfig

`getConfig(c, isPub, client)` 是 `conf` 连接的循环处理体（[bridge.go#L391](bridge/bridge.go#L391)），支持四种标记：

| 标记 | 方向 | 行为 |
|---|---|---|
| `stus` | npc 查询任务状态 | 读 32 字节 md5(vkey) → 反查 clientId → 拼 Hosts/Tasks 的 `Remark + "*#*"`，回 `int32` 长度 + 内容 |
| `conf` | npc 上送完整配置 | `GetConfigInfo` → `NewClient`（校验 vkey 查重）→ `WriteAddOk` + 下发 `VerifyKey`，并在 Bridge 中占位 `NewClient(nil,nil,nil,"")` |
| `host` | npc 上送域名 | `Location` 为空补 `/`；`HasHost` / `IsHostExist` 判重 |
| `task` | npc 上送隧道 | `GetPorts` 拆分多端口；多端口时 `Remark_Port` 且 `TargetStr` 追加 `:port`；`NoStore=true`；`HasTunnel` 判重；`TestServerPort` 后 `OpenTask <- tl` |

任何一步失败且 `client != nil` 时调用 `DelClient`，最后 `c.Close()`。配置面因此是「客户端驱动、服务端校验」的模型：**配置的源头在 npc 的 ini 文件，nps 只负责落库和起监听**。

## 10. 任务生命周期

### 10.1 AddTask / StartTask / StopServer / DelTask

| 函数 | 行为 | 隐患 |
|---|---|---|
| `AddTask` | `secret`/`p2p` 仅占位；其余 `TestServerPort` → `flow_store_interval` 起落盘 ticker → `NewMode` → `RunList.Store` → `go svr.Start()`，失败 `RunList.Delete` | 每次调用都新建一个 `flowSession` ticker；Start 失败不回写 `Status` |
| `StartTask` | `AddTask(t)` 后强制 `t.Status=true` + `UpdateTask` | **完全忽略 AddTask 的 error**，端口占用时界面仍显示运行中 |
| `StopServer` | `RunList.Load` → `svr.Close()` → `t.Status=false` + `UpdateTask` → `RunList.Delete` | 任务不在 RunList 时报 "task is not running"，但 Web 层仍会显示失败 |
| `DelTask` | 若在 RunList 则先 `StopServer`，再 `DelTask` 落库 | 删除运行中任务依赖 StopServer 成功 |

`secret` / `p2p` 没有监听端口，所以 AddTask 只占位，实际入口分别在 `SecretChan` 和 P2P UDP 交换服务。

### 10.2 端口校验规则（tool.TestServerPort）

```
p2p / secret            → true（无需端口）
p > 65535 || p < 0      → false
allow_ports 非空且不含 p → false
udp  → common.TestUdpPort
其他 → common.TestTcpPort
```

`httpHostServer` 在 AddTask 中被显式豁免，因为它使用配置里的 80/443，而不是任务自身端口。

## 11. 各代理模式内部实现

### 11.1 TCP / File（TunnelModeServer）

`Start`（[server/proxy/tcp.go](server/proxy/tcp.go)）：

```
conn.NewTcpListenerAndProcess(ServerIp:Port, func(c *conn.Conn){
    CheckFlowAndConnNum(client)     // 流量上限 + 连接数上限
    s.process(c, s)                 // ProcessTunnel / ProcessHttp / HandleTrans
    task.Client.AddConn()           // 连接数 +1
})
```

`ProcessTunnel` 极简：`GetRandomTarget()` 取目标 → `DealClient(..., CONN_TCP, nil, task.Flow, task.Target.LocalProxy)`。真正的双向复制在 `BaseServer.DealClient`（[server/proxy/base.go](server/proxy/base.go)）：

```
NewLink(tp, addr, Crypt, Compress, RemoteAddr, localProxy)
  → SendLinkInfo
  → 回调 f()（例如 CONNECT 先回 200）
  → conn.CopyWaitGroup(target, c.Conn, link.Crypt, link.Compress,
                        client.Rate, flow, true, rb)
```

`flow` 在复制过程中按方向累加 Inlet/Export，这也是面板流量的唯一来源。

### 11.2 HTTP 正向代理（ProcessHttp）

```
c.GetHost()   // 从请求行/ Host 头解析出 host:port
若 method == CONNECT：
    回 "HTTP/1.1 200 Connection established\r\n\r\n"
    rb = nil（之后是裸字节流）
auth(U, P)    // 401 时写 UnauthorizedBytes 并关闭
DealClient(..., CONN_TCP, nil, task.Flow, LocalProxy)
```

`GetHost` 里对 `Opaque == "443"` 补 `:443`，否则无端口补 `:80`，见 [lib/conn/conn.go](lib/conn/conn.go)。

### 11.3 SOCKS5

支持三种命令，但实现完整度不同：

| 命令 | 实现 |
|---|---|
| CONNECT (1) | 完整：解析 IPv4/IPv6/域名 + 大端端口 → `doConnect` → `DealClient`，成功后 `sendReply(succeeded)` |
| BIND (2) | **空实现**：只识别命令，不建监听也不回 reply，连接会悬挂 |
| UDP ASSOCIATE (3) | 完整：`net.ListenUDP(serverIp:0)` → 回绑定地址 → `NewLink("udp5","",...)` → 本地 UDP ↔ 目标双向转发 |

认证：`(Cnf.U!="" && Cnf.P!="")` 或 `MultiAccount.AccountMap` 非空时回 `0x02`，否则回 `0x00` 免认证；`Auth` 按 RFC1929 校验，成功 `{1,0}` 失败 `{1,1}`。

UDP ASSOCIATE 的两个方向：

- 本地 → npc：`reply.ReadFrom` 收到的是**带 SOCKS5 UDP 头**的完整报文，nps 原样 `target.Write(b[:n])` 交给 npc 解析
- npc → 本地：读 `int32 LE` 长度前缀 + 数据，再 `reply.WriteTo(b[:l], clientAddr)` 发回客户端
- `clientAddr` 取第一条报文的来源地址；`l <= 0 || l >= PoolSizeUdp` 直接退出
- 主循环靠 `c.Read` 保活，TCP 控制连接断开即回收

### 11.4 UDP（UdpModeServer）

单端口多会话，核心是 `addrMap sync.Map`（来源地址 → 目标连接）：

```
ReadFromUDP → go process(addr, data)
  ├─ addrMap 命中 → 直接 Write
  └─ 未命中 → CheckFlowAndConnNum → NewLink(CONN_UDP) → SendLinkInfo
              → conn.GetConn(...) → addrMap.Store
              → 回包循环：SetReadDeadline(now+10min) → Read → WriteTo(addr)
```

每个来源地址独占一条 mux 子流，10 分钟无回包即自然退出，读错误时 `addrMap.Delete` + `defer target.Close()` 完成回收。

注意这里是先 `addrMap.Load` 未命中、再 `SendLinkInfo`（一次网络往返）、最后才 `Store`。同一来源的两个首包并发到达时可能都未命中，从而建立**两条**子流、后写入的覆盖先写入的，前者失去索引但仍在后台读包。

### 11.5 Web 控制台（WebServer）

```
web_port == 0 → 永久阻塞（只做反代，不开控制台）
SessionOn = true
SetStaticPath(runPath/web/static)
SetViewsPath(runPath/web/views)
connection.GetWebManagerListener()   // 可能与 bridge 共用端口（pmux）
beego.InitBeforeHTTPRun()
web_open_ssl == "true" ? http.ServeTLS : http.Serve
```

### 11.6 HTTP/HTTPS 反向代理（httpHostServer）

`NewHttp` 同时管理 80 与 443 两个监听：

- 80：`handleTunneling` 用 `http.Hijacker` 取裸连接 → `handleHttp`
- 443：`HttpsServer`，见 11.7

`handleHttp` 的 `reset:` 标签是整段最复杂的逻辑：

```
GetInfoByHost(r.Host, r)   // 按域名 + Location 最长前缀匹配
CheckFlowAndConnNum / auth
GetRandomTarget
NewLink("http", targetAddr, ...) → SendLinkInfo(host.Client.Id, lk, nil)
响应 goroutine：
    useCache && URL.Path 含 "." → DumpResponse → 写回 + cache.Add
    否则 NewLenConn(c) + resp.Write
请求循环：
    命中缓存 → 直接写回 → goto readReq
    ChangeHostAndHeader → r.Write(NewLenConn(client))
readReq:
    读下一请求 → 设 Scheme → resetReqMethod → Host 变化则 isReset + goto reset
```

两个「历史包袱」：

- `resetReqMethod` 把 `"ET"` 改回 `"GET"`、`"OST"` 改回 `"POST"`，源码注释写着 “What happened，Why one character less???”
- `NewServer` 用空的 `TLSNextProto` 关闭 HTTP/2，避免 h2 的流语义与「一请求一子流」模型冲突

### 11.7 HTTPS 与 SNI

`https_just_proxy=true` 时是**纯 SNI 透传**：只解析 ClientHello 拿 serverName，然后直接 `DealClient`，证书由内网服务自己提供。

关闭时是**按 SNI 动态加载证书本地终止**：

```
先建 default listener（https_default_cert_file/key_file）
Accept → GetServerNameFromClientHello
  ├─ SNI 为空 → default
  ├─ httpsListenerMap 命中 → 复用
  └─ GetInfoByHost(serverName) 取 CertFilePath/KeyFilePath
       不存在 → default
       存在 → NewHttpsListener + NewHttps + Store
最后把连接放进 acceptConn.Rb 投递
```

`GetServerNameFromClientHello` 固定读 4096 字节，`n < 42` 直接返回空；分片 ClientHello 会退化到 default 证书。

### 11.8 tcpTrans（透明代理）

仅 Linux 有效：`SyscallConn` + `GetsockoptIPv6Mreq(IPPROTO_IP, SO_ORIGINAL_DST=80)` 取原始目的地址，再 `DealClient`。Windows 版本 `HandleTrans` 直接 `return nil`，等于**静默不转发**。

### 11.9 Secret

客户端侧监听本地 TCP，收到用户连接后新开一条 `sert` 连接并写 `md5(password)`；服务端 `typeDeal` 把连接投给 `SecretChan`，`DealBridgeTask` 用 `GetTaskByMd5Password` 找到任务，直接 `DealClient(CONN_TCP)`。整条链路不需要 mux，因此 Secret 不依赖 `chan` 连接。

### 11.10 P2P

- 服务端起 `p2p_port / +1 / +2` 三个 UDP 交换器，报文按 `*#*` 拆分
- `arr[1] == "p2pv"`（visitor）存地址，最多等 20 秒（每秒轮询）等 provider
- provider 到达后双方互换地址并 `delete(s.p2p, key)`
- 真正的打洞由客户端完成（`sendP2PTestMsg`，见第 1 章）
- 打洞失败时客户端回退为 Secret

## 12. pmux 端口复用

当 `web_port` / `http_proxy_port` / `https_proxy_port` 与 `bridge_port` 冲突时，`connection.InitConnectionService()` 会启用端口复用，所有流量先经过 `lib/pmux`（[lib/pmux/pmux.go](lib/pmux/pmux.go)）。

分流规则（读首包 3 字节 → `BytesToNum` 后与常量比对）：

| 首包 | 判定 |
|---|---|
| `GET ` / `POST` / `HEAD` / `PUT` / `DELETE` / `CONNECT` / `OPTIONS` / `TRACE` | HTTP；再读到 `Host:` 行，`Host` 的 IP == `managerHost` 时进 managerConn，否则 httpConn |
| `TST` | clientConn（bridge 握手） |
| 其他 | httpsConn（并置 `readMore=true`，后续读到字节继续吐给 TLS） |

投递时用 10 秒 timer，超时丢弃；`Close()` 幂等关闭四个 channel 与 listener。`PortConn.Read` 先吐 `Peek` 出来的剩余字节，再按 `readMore` 决定是否继续读底层——这是「偷看首包但不丢字节」的关键。

## 13. 持久层：sync.Map + JSON

- 单例 `file.GetDb()`，启动时加载 `conf/clients.json`、`conf/tasks.json`、`conf/hosts.json`
- 记录以 `"\n*#*"` 分隔追加，写 `*.tmp` → `Sync` → `Close` → `os.Rename` 原子替换
- 三张表各有独立 `sync.Mutex`，避免互相阻塞
- `NoStore=true` 的记录（配置文件下发的临时 client/host/task）**不落盘**
- 加载 Host/Task 时必须先成功 `GetClient(post.Client.Id)`，否则跳过——保证引用完整性
- `GetClientId` / `GetTaskId` / `GetHostId` 用 `atomic.AddInt32` 生成自增 Id

注意：`loadSyncMapFromFile` 读文件失败会 **直接 panic**。仓库自带三个 0 字节 JSON 文件所以首次启动正常；如果部署时漏拷 `conf/*.json`，进程会直接崩在启动阶段。

## 14. Web 控制台的服务端逻辑

### 14.1 两套鉴权

| 方式 | 触发条件 | 结果 |
|---|---|---|
| Session | 登录成功 | `auth=true`；admin 另有 `isAdmin=true` |
| 签名直通 | `auth_key` + `timestamp` 且 `|now-ts| <= 20` 且 `md5(configKey+ts)==签名` | 直接 `SetSession("isAdmin", true)`，供 `docs/api.md` 的脚本调用 |

`Prepare()` 在未登录时 `Redirect(web_base_url+"/login/index", 302)`；非 admin 会把 session 里的 `clientId` 注入参数并调用 `CheckUserAuth()`：

- `client` 控制器：禁止 `add`，且 `id != 0` 时必须等于 session 的 clientId
- `index` 控制器：`id != 0` 时按 action 名是否含 `h` 判断是 Host 还是 Tunnel，再校验归属

### 14.2 登录与注册

`doLogin`（[web/controllers/login.go](web/controllers/login.go)）：

1. `clearIprecord()`：1% 概率清理 60 秒前的失败记录
2. 同 IP 60 秒内失败 ≥10 次直接拒绝
3. admin：匹配 `web_username / web_password` → `isAdmin=true`，删除 clientId/username session，并 `Bridge.Register.Store(ip, now+2h)`（供 `ip_limit` 使用）
4. 普通用户（`allow_user_login=true`）：遍历 Clients，跳过 `!Status` / `NoDisplay`；未设置网页账号密码的客户端用 `username=="user" && password==VerifyKey` 登录，否则匹配 `WebUserName/WebPassword`
5. 成功 `SetSession("auth", true)`；失败按 IP 累加失败次数

`Register` 仅在 `allow_user_register=true` 时开放，用户名不能为空、不能等于 admin，`NewClient` 失败返回错误。

## 15. 本轮新发现的缺陷与风险

> 均为只读分析所得，未修改代码。

| # | 位置 | 问题 | 影响 |
|---|---|---|---|
| 1 | [server.go#L54-L85](server/server.go#L54-L85) | `DealBridgeTask` 有**两个** `<-Bridge.OpenTask` case，第二个 `StartTask(tunnel.Id)` 永远不可达 | 已用最小程序验证 Go 允许重复 case 且编译通过；「启动任务」语义实际由 `AddTask` 承担，`Status` 不会回写 |
| 2 | [server.go#L196-L198](server/server.go#L196-L198) | `AddTask` 内每次 `go flowSession(...)` | `flow_store_interval>0` 时每个任务一个 ticker，任务越多落盘越频繁、goroutine 线性增长 |
| 3 | [server.go#L218-L226](server/server.go#L218-L226) | `StartTask` 忽略 `AddTask` 错误并强制 `Status=true` | 端口被占用时面板显示「运行中」，实际无监听 |
| 4 | [server/proxy/p2p.go](server/proxy/p2p.go) | `s.p2p` 是普通 `map`，`handleP2P` 在 goroutine 中并发读写且无锁 | 并发访问可能触发 `concurrent map read and map write` panic |
| 5 | [server/proxy/socks5.go](server/proxy/socks5.go) | `handleBind` 空实现 | SOCKS5 BIND 请求的连接会一直悬挂 |
| 6 | [server/proxy/transport_windows.go](server/proxy/transport_windows.go) | `HandleTrans` 直接 `return nil` | Windows 下 `tcpTrans` 无任何转发，且无日志 |
| 7 | [server/test/test.go](server/test/test.go) | 使用旧配置键 `httpProxyPort` / `pemPath` / `keyPath`，且未被 `cmd/nps` 调用 | 启动阶段不做端口冲突自检 |
| 8 | [web/controllers/client.go](web/controllers/client.go) | `ChangeStatus` 成功分支 `AjaxOk` 后没有 return，继续执行 `AjaxErr` | 依赖 `AjaxOk` 内部 `StopRun` panic 才侥幸正确，代码意图混乱 |
| 9 | [lib/rate/rate.go](lib/rate/rate.go) | `Get` 中 100ms ticker 没有 `stop` 分支 | 高并发等待会创建大量短命 ticker，`Rate.Stop()` 无法回收 |
| 10 | [lib/file/file.go](lib/file/file.go) | `loadSyncMapFromFile` 读失败直接 `panic` | 缺少 `conf/*.json` 时启动即崩溃 |
| 11 | [lib/common/util.go](lib/common/util.go) | `IsPort` 上界写成 `65536` | 非法端口 65536 可穿过校验 |
| 12 | [bridge/bridge.go](bridge/bridge.go) | `cliProcess` 读 3 字节后不校验是否 `TST` | 任意 TCP 连接都会被当作握手处理，浪费 fd / 增加探测面 |
| 13 | [lib/crypt/tls.go](lib/crypt/tls.go) | `NewTlsServerConn` 中 `var err error; if err != nil` 是死代码 | TLS 包装失败不会被感知 |
| 14 | [server/server.go#L352-L436](server/server.go#L352-L436) | `GetDashboardData` 直接 `dealClientData()`，采集 IO 时 `Sleep(500ms)`；且 `dealClientData` 直接给 `Flow.InletFlow/ExportFlow` 赋值，未持有 `Flow.RWMutex` | 打开首页至少阻塞 0.5 秒；与复制协程里的 `Flow.Add`（加锁累加）构成数据竞争 |
| 15 | [lib/pmux/pmux.go](lib/pmux/pmux.go) | 非 HTTP / 非 `TST` 首包一律当 HTTPS | 未知协议占用 `httpsConn`，10 秒后才释放 |
| 16 | [server/proxy/https.go](server/proxy/https.go) | 固定读 4096 字节解析 ClientHello，`n<42` 返回空 | 分片 ClientHello 会回落 default 证书，SNI 匹配失败 |
| 17 | [server/proxy/udp.go](server/proxy/udp.go) | `Load` → `SendLinkInfo` → `Store` 之间存在时间窗 | 同一来源并发首包可能建立多条子流，后写覆盖先写，前一条失去索引仍占用连接 |

上一轮已记录的 6 条安全风险与 8 条稳定性问题仍有效，本节只列本轮**新增**项。

## 16. 服务端配置项速查（conf/nps.conf）

| 配置 | 作用 | 消费位置 |
|---|---|---|
| `bridge_type` / `bridge_port` | 控制面协议与端口 | `StartNewServer` / `StartTunnel` |
| `public_vkey` | 公共客户端 vkey，空则禁用 | `InitFromCsv`、登录计数、`DelClient` |
| `ip_limit` | 是否启用 `Bridge.Register` 白名单 | `bridge.NewTunnel` → `SendLinkInfo` |
| `disconnect_timeout` | mux 断线判定秒数 | `nps_mux.NewMux` |
| `p2p_port` / `p2p_ip` | P2P 交换端口与对外 IP | `StartNewServer` / 面板 |
| `http_proxy_port` / `https_proxy_port` | 反代监听 | `NewMode("httpHostServer")` |
| `https_just_proxy` | 纯 SNI 透传开关 | `HttpsServer.Start` |
| `http_cache` / `http_cache_length` | 静态资源缓存 | `NewHttp` |
| `http_add_origin_header` | 追加 `X-Forwarded-*` | `ChangeHostAndHeader` |
| `allow_ports` | 端口白名单 | `tool.InitAllowPort` / `TestServerPort` |
| `flow_store_interval` | 流量落盘周期（分钟） | `AddTask` → `flowSession` |
| `web_port` / `web_open_ssl` / `web_base_url` | 控制台 | `WebServer.Start` / 路由 |
| `auth_key` / `auth_crypt_key` | API 签名 / AES 密钥 | `base.Prepare` / `auth.GetAuthKey` |
| `allow_*` 系列 | 用户权限开关 | `base.Prepare` 注入模板 + 控制器校验 |

## 17. 一次 TCP 请求的完整时序

```
用户 1.2.3.4:随机端口
  │  connect  nps:task.Port
  ▼
TunnelModeServer.Start (accept)
  ├─ CheckFlowAndConnNum：FlowLimit / MaxConn
  ├─ ProcessTunnel
  │    └─ GetRandomTarget：TargetArr 轮询（0 个 → 报错，1 个 → 直取）
  └─ DealClient
       ├─ NewLink("tcp", "10.0.0.5:8080", Crypt, Compress, "1.2.3.4:x", LocalProxy)
       ├─ SendLinkInfo
       │    ├─ LocalProxy? → 本机 net.Dial
       │    └─ 否 → Client.tunnel.NewConn() → SendInfo(Link)
       │            └─ npc 读 Link → 本地 Dial target
       └─ CopyWaitGroup(target, c.Conn, ...)
            └─ 双向复制，同时累加 task.Flow / host.Flow / client.Rate 限速
```

至此，服务端的职责闭环：**握手鉴权 → 建 mux → 建监听 → 收连接 → 借子流 → 下发 Link → 双向复制 → 流量记账**。

---
