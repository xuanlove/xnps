# nps 项目日记

> 日期：2026-10-27
> 主题：协议细节、代理实现、Web API
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
