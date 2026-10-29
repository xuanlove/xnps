# nps-admin 管理端部署文档

适用版本：**xnps 0.26.10-ext0.3.0 / nps-admin 0.4.0（I3 可观测性与运维）**

本文覆盖单机生产部署：依赖安装、环境配置、PHP-FPM/Nginx、队列与调度、权限、支付与 ACME、首次验收、升级回滚、备份与故障排查。

---

## 1. 架构与组件

```
                    Internet
                       │ HTTPS
              ┌────────▼────────┐
              │  Nginx / TLS    │
              └────────┬────────┘
                       │ FastCGI
              ┌────────▼────────┐        ┌──────────────┐
              │ PHP-FPM         │───────▶│ MySQL 8      │
              │ nps-admin       │        └──────────────┘
              │ (Laravel 12)    │        ┌──────────────┐
              └───┬────────┬────┘───────▶│ Redis（推荐） │
                  │        │             └──────────────┘
      HTTPS WebAPI│        │ queue/cron
                  ▼        ▼
        ┌──────────────────────┐
        │ xnps 服务器（多台）    │  ext ≥ 0.3.0
        │ /ext/* + 原生 WebAPI  │
        └──────────────────────┘
```

| 组件 | 作用 | 是否必需 |
|---|---|---|
| nps-admin（Laravel） | 管理 API + `/admin` 控制台 | 必需 |
| PHP-FPM + Nginx | 运行 Web 与管理 UI | 必需 |
| MySQL / PostgreSQL | 镜像、账单、审批、支付、审计 | 必需（生产不建议 SQLite） |
| Redis | 缓存、队列、原子锁（flow 防重叠） | 推荐 |
| Supervisor | 队列 worker + `schedule:work` 调度 | 必需 |
| Node.js | 构建前端资源（仅构建期） | 发布包已内置可省略 |

## 2. 部署前准备

### 2.1 主机与系统

- Linux（Ubuntu 22.04/24.04、Debian 12 等），2 vCPU / 4 GB RAM 起步；磁盘按“制品库 + 备份 + 指标”预留。
- 域名与 TLS 证书（支付回调、ACME 管理域名均要求 HTTPS）。
- 所有机器启用 NTP/chrony：nps WebAPI 签名窗口仅 **±20 秒**，时钟漂移会导致 302/鉴权失败。

### 2.2 软件版本

| 软件 | 版本要求 |
|---|---|
| PHP | ≥ 8.2（推荐 8.3/8.4） |
| Composer | 2.x |
| MySQL | 8.0+（utf8mb4）或 PostgreSQL 15+ |
| Nginx | 1.24+ |
| Redis | 6/7（可选） |
| Node.js | 22 LTS（仅源码构建前端时需要） |
| Supervisor | 4.x |

PHP 扩展：`openssl`、`pdo_mysql`（或 `pdo_pgsql`）、`mbstring`、`curl`、`xml`、`fileinfo`、`bcmath`、`intl`、`zip`、`ctype`、`tokenizer`、`json`；使用 Redis 时安装 `phpredis`。

### 2.3 nps 服务器侧前置条件

- 运行 **xnps ext ≥ 0.3.0**；`POST /ext/version` 返回 `ext_version`。
- 管理端能访问 nps 的 WebAPI（`api_url`，默认 `http://host:web_port`；若配置了 `web_base_url`，`api_url` 需包含该前缀）。
- nps 能出方向访问管理端（`/api/artifacts/*/download/*` 制品下载）与 Let's Encrypt（内置 ACME）。
- `auth_key` 与 nps.conf 一致；nps 防火墙放行 WebAPI 端口与 bridge 端口。
- 内置 ACME 额外要求：目标域名解析到该 nps，`http_proxy_port`（通常 80）公网可达。

## 3. 安装步骤

### 3.1 获取代码

```bash
sudo useradd -r -s /usr/sbin/nologin xnps || true
sudo mkdir -p /var/www/xnps-admin
sudo tar -xzf nps-admin-0.3.0.tar.gz -C /tmp
sudo mv /tmp/nps-admin/* /var/www/xnps-admin/
sudo chown -R www-data:www-data /var/www/xnps-admin
```

> 发布包已包含构建好的 `public/build`。若使用 Git 源码，需执行 `npm ci && npm run build`（见 3.6）。

### 3.2 安装 PHP 依赖

```bash
cd /var/www/xnps-admin
sudo -u www-data composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

### 3.3 配置环境变量

```bash
sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
sudo chmod 600 .env
```

生产 `.env` 最小模板（完整项见附录 A）：

```ini
APP_NAME="XNPS Admin"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://admin.example.com
ASSET_URL=/

LOG_CHANNEL=json
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xnps_admin
DB_USERNAME=xnps_admin
DB_PASSWORD=<强密码>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<强密码>
REDIS_PORT=6379
REDIS_QUEUE_RETRY_AFTER=3700

SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

PAYMENT_MOCK_ENABLED=false
```

要点：

- `APP_DEBUG=false`，否则异常页会泄露堆栈与配置。
- `ASSET_URL=/`：前端资源使用相对路径，避免反向代理/多域名访问时 `@vite` 生成跨域绝对 URL 导致页面空白。
- `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` 必须 **大于最长 Job 运行时间**（升级 Job 可达数十分钟），否则同一 Job 会被并发重试。

### 3.4 创建数据库并迁移

```sql
CREATE DATABASE xnps_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'xnps_admin'@'127.0.0.1' IDENTIFIED BY '<强密码>';
GRANT ALL PRIVILEGES ON xnps_admin.* TO 'xnps_admin'@'127.0.0.1';
FLUSH PRIVILEGES;
```

```bash
cd /var/www/xnps-admin
sudo -u www-data php artisan migrate --force
```

迁移包含：服务器/镜像、审批、钱包账单、证书、指标告警、升级制品、**payments（0.3.0 新增）**、队列/缓存/会话表。

### 3.5 创建管理员与纳管服务器

```bash
sudo -u www-data php artisan nps:admin admin@example.com
# 输出自动生成的密码（或显式指定：--password='...'）

# 可选：CLI 注册服务器（探活 + 首次全量对账，适合 10 万级冷启动）
sudo -u www-data php artisan nps:register edge-1 http://10.0.0.11:8080 <auth_key>
```

> 首次全量对账为同步执行：10 万级 client/20 万 tunnel 实测约 7 分钟。**大实例请用 CLI 注册**（CLI 无 Web 超时限制），或在 Nginx/PHP-FPM 放宽超时（见 §5）。

### 3.6 前端资源

发布包已含 `public/build`，可跳过。源码部署：

```bash
cd /var/www/xnps-admin
sudo -u www-data npm ci
sudo -u www-data npm run build
```

### 3.7 目录权限

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
sudo chmod 600 .env
```

`storage/app/private/artifacts`（制品库）与 `storage/app/private/conf-backups`（nps.conf 快照）需保证磁盘余量。

### 3.8 生成缓存并重载 PHP-FPM

```bash
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan view:cache
sudo systemctl reload php8.3-fpm
```

> **不要执行 `php artisan route:cache`**：`routes/web.php` 含闭包路由（`/`、`/admin/{any?}`），会报 `Unable to prepare route ... for serialization`。`config:cache` 后修改 `.env` 必须重新执行。

## 4. 队列 worker（Supervisor）

任务分布：

| 队列 | 任务 | 建议 worker 超时 |
|---|---|---|
| `nps-sync` | 版本增量、在线/流量采集、指标采集、对账 | 900s |
| `nps-provision` | 审批开通（Saga） | 300s |
| `nps-upgrade` | 滚动升级编排（canary/观察/回滚） | 3600s |

`/etc/supervisor/conf.d/xnps-admin.conf`：

```ini
[program:xnps-sync]
command=/usr/bin/php /var/www/xnps-admin/artisan queue:work --queue=nps-sync --sleep=1 --tries=1 --timeout=900 --max-time=3600
process_name=%(program_name)s_%(process_num)02d
numprocs=2
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=1200
redirect_stderr=true
stdout_logfile=/var/log/xnps-admin/sync.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5

[program:xnps-provision]
command=/usr/bin/php /var/www/xnps-admin/artisan queue:work --queue=nps-provision --sleep=1 --tries=1 --timeout=300 --max-time=3600
numprocs=1
user=www-data
autostart=true
autorestart=true
stopwaitsecs=600
redirect_stderr=true
stdout_logfile=/var/log/xnps-admin/provision.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5

[program:xnps-upgrade]
command=/usr/bin/php /var/www/xnps-admin/artisan queue:work --queue=nps-upgrade --sleep=1 --tries=1 --timeout=3600 --max-time=7200
numprocs=1
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3900
redirect_stderr=true
stdout_logfile=/var/log/xnps-admin/upgrade.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5

; 秒级同步节拍（10s/30s）依赖常驻 scheduler，必须单实例
[program:xnps-scheduler]
command=/usr/bin/php /var/www/xnps-admin/artisan schedule:work
numprocs=1
user=www-data
autostart=true
autorestart=true
stopwaitsecs=30
redirect_stderr=true
stdout_logfile=/var/log/xnps-admin/schedule.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
```

```bash
sudo mkdir -p /var/log/xnps-admin && sudo chown www-data:www-data /var/log/xnps-admin
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
```

约定：

- `REDIS_QUEUE_RETRY_AFTER`（或 `DB_QUEUE_RETRY_AFTER`）应大于 upgrade worker 的 `--timeout`，例如 3700 > 3600。
- 单次升级目标数建议 ≤10 台：`UpgradeRunJob` 超时随服务器数线性增长，过大建议拆分为多个 Run。
- 发布后执行 `php artisan queue:restart` 让 worker 加载新代码。

## 5. Nginx 与 PHP-FPM

### 5.1 Nginx

```nginx
server {
    listen 80;
    server_name admin.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name admin.example.com;

    root /var/www/xnps-admin/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/admin.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/admin.example.com/privkey.pem;

    # 制品上传上限 256MB（PHP 侧同样需放宽）
    client_max_body_size 300m;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        # 全量对账 / 证书启用 / 轮换可能持续数分钟
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
    }

    location ~* ^/build/ {
        expires 30d;
        access_log off;
        try_files $uri =404;
    }

    location ~ /\.(env|git) {
        deny all;
    }
}
```

### 5.2 PHP-FPM（`/etc/php/8.3/fpm/php.ini` 与 pool）

```ini
# 制品最大 256MB；下载端点会整文件读入内存，建议 1G
memory_limit = 1024M
max_execution_time = 600
upload_max_filesize = 300M
post_max_size = 310M
max_input_vars = 5000
opcache.enable = 1
opcache.validate_timestamps = 0   ; 发布后需 reload php-fpm
```

```ini
; /etc/php/8.3/fpm/pool.d/www.conf
request_terminate_timeout = 600s
```

同步接口耗时参考：`POST /api/nps/servers/{id}/sync` 为同步全量对账（30 万行约 7m32s）；`enable-https` 约 90s；ACME 签发最长约 210s。超过 Nginx/FPM 超时会 504，**大实例优先用 CLI 完成首次对账**。

## 6. 调度（schedule:work）

秒级同步由常驻 `php artisan schedule:work` 按节拍派发一次性 Job（Job 实现 `ShouldBeUnique`，重复派发会被丢弃）。要求在 Supervisor 中保持 **单实例** 运行（见 §4 `xnps-scheduler`）。

> 不要同时运行 `schedule:work` 与 cron `schedule:run`，否则同一节拍会被重复派发。
>
> 仅使用 cron `schedule:run` 时，Laravel 会在分钟内重复执行到期的亚分钟事件；但一次 `schedule:run` 可能持续到分钟末，需用 `flock` 防止相邻 cron 进程重叠。

调度内容（`php artisan schedule:list` 可核对）：

| 频率 | 任务 |
|---|---|
| 每 10 秒 | 派发 `SyncVersionJob`（版本增量） |
| 每 30 秒 | 派发 `SyncOnlineJob`（在线状态）、`CollectMetricsJob`（指标采集） |
| 每 5 分钟 | 派发 `SyncFlowJob`（流量分片）、告警检查、支付订单过期回收 |
| 每小时 | 审批过期扫描 |
| 每 6 小时 | 全量对账 |
| 每日 03:00 | 证书指纹对账 |
| 每日 04:00 | ACME 证书自动续期（到期前 30 天） |
| 每日 | 审计日志清理、指标清理 |
| 每月 1 日 00:05 | 月度结算 |

备选 cron（仅当无法常驻 scheduler 时；`flock` 防止进程重叠）：

```cron
* * * * * www-data cd /var/www/xnps-admin && flock -n /tmp/xnps-schedule.lock /usr/bin/php artisan schedule:run >> /var/log/xnps-admin/schedule.log 2>&1
```

> 队列 worker 停止时不会产生同步；调度与队列都需常驻。

## 7. 首次上线验收清单

| # | 检查项 | 命令 / 操作 | 期望 |
|---|---|---|---|
| 1 | 健康检查 | `curl -fsS https://admin.example.com/up` | 200 |
| 2 | 管理员登录 | `POST /api/admin/login` | 返回 token |
| 3 | UI | 浏览器打开 `/admin` | 登录页正常渲染，无控制台错误 |
| 4 | 队列 | `supervisorctl status`；`php artisan queue:monitor nps-sync,nps-provision,nps-upgrade` | RUNNING，积压可控 |
| 5 | 调度 | `php artisan schedule:list` | 任务齐全 |
| 6 | 纳管服务器 | 控制台注册或 `nps:register` | `/ext/version` 可达，`ext_version ≥ 0.3.0` |
| 7 | 同步 | 仪表盘查看 `last_synced_at` / 镜像计数 | 30s 内更新 |
| 8 | 审计 | `SELECT * FROM nps_api_logs ORDER BY id DESC LIMIT 5` | 写操作有记录且敏感键为 `******` |
| 9 | 支付（如启用） | 小额下单 → 渠道回调 | 订单 paid，钱包/账单幂等入账 |
| 10 | 证书（如启用） | staging ACME 或人工上传 → 部署 | 指纹对账一致 |

## 8. 支付配置（支付宝 / 微信支付 v3）

### 8.1 支付宝

```ini
PAYMENT_DEFAULT_CHANNEL=alipay
ALIPAY_ENABLED=true
ALIPAY_APP_ID=2021xxxxxxxx
ALIPAY_PRIVATE_KEY=/etc/xnps-admin/alipay_app_private.pem   # 应用私钥（RSA2）
ALIPAY_PUBLIC_KEY=/etc/xnps-admin/alipay_public.pem         # 支付宝公钥
ALIPAY_GATEWAY=https://openapi.alipay.com/gateway.do
ALIPAY_NOTIFY_URL=https://admin.example.com/api/payments/alipay/notify
```

- 产品：当面付（`alipay.trade.precreate`），返回二维码内容。
- 回调为 `POST` 表单；验签使用支付宝公钥；金额按分比对。
- 成功后必须返回纯文本 `success`（已内置）。

### 8.2 微信支付 v3

```ini
WECHAT_PAY_ENABLED=true
WECHAT_PAY_APP_ID=wx...
WECHAT_PAY_MCH_ID=16...
WECHAT_PAY_SERIAL_NO=商户API证书序列号
WECHAT_PAY_PRIVATE_KEY=/etc/xnps-admin/wechat_mch_private.pem
WECHAT_PAY_API_V3_KEY=<32 字节 APIv3 密钥>
WECHAT_PAY_PLATFORM_CERT=/etc/xnps-admin/wechat_platform_cert.pem
WECHAT_PAY_NOTIFY_URL=https://admin.example.com/api/payments/wechat/notify
```

- Native 下单；回调使用平台证书验签 + APIv3 Key AES-256-GCM 解密。
- 平台证书会轮换：证书更新后替换文件并 `config:clear` 或重启 PHP-FPM。

### 8.3 通用要求

- 回调地址必须公网 HTTPS 可达，且未被 WAF/CDN 拦截 `POST`。
- 生产必须 `PAYMENT_MOCK_ENABLED=false`；mock 仅用于联调。
- 当前版本未实现退款与对账报表，退款需在渠道后台操作并人工核对。

## 9. 证书与 ACME

```ini
NPS_ACME_MODE=                                    # 空=仅人工上传；builtin | certd
NPS_ACME_EMAIL=ops@example.com
NPS_ACME_DIRECTORY_URL=                           # 空=LE 生产；测试用 staging
NPS_ACME_CERTD_URL=
NPS_ACME_CERTD_TOKEN=
NPS_CERTD_DEPLOY_URL=
NPS_CERTD_TOKEN=

# 可观测性与告警（I3）
NPS_METRICS_TOKEN=
NPS_ALERT_WEBHOOK_URL=
NPS_ALERT_DEBOUNCE_MINUTES=60
NPS_SLOW_QUERY_MS=500
```

- **builtin**：nps 侧 HTTP-01 签发；不支持通配符；要求域名解析到该 nps 且 80 端口可达；首次务必先用 staging 目录验证。
- **certd**：DNS-01，支持通配符；签发与部署可走 certd，未配置部署地址时回退 native 直推 `/ext/cert`。
- **自动续期**：每日 04:00 扫描 `not_after <= 30 天` 的 ACME 证书（`acme` / `acme-builtin` 来源），重新签发并按原部署记录逐台滚动替换（`autoRestart=true`，每台 nps 会重启一次）。manual 证书不自动续签，只由监控告警提示人工上传。
- 启用 HTTPS 顺序由控制台编排：推证书（不重启）→ 写 `web_open_ssl=true` → 重启 → HTTPS 探活 → 切换 `api_url`。不要手工先开 SSL。
- **自锁恢复**：若启用失败导致 WebAPI 不可达，登录 nps 主机把 `conf/nps.conf` 的 `web_open_ssl` 改回 `false` 并重启 nps，再从控制台重试。
- ACME 账户密钥保存在 nps `conf/acme_account.key`（0600），请纳入备份。

## 10. 版本升级（制品库）

1. 上传制品：版本号必须为 `{core}-ext{ext}`（如 `0.26.10-ext0.3.0`），字符限 `[A-Za-z0-9._-]`，≤256MB。
2. 制品下载 URL 的 token 默认 **7 天过期**（`nps.artifact_token_days`），可在控制台轮换；下载文件流式输出。
3. nps 侧 `url` 模式仅允许 **HTTPS**、禁止重定向；默认拒绝内网/回环地址。若制品库在内网，需在 nps.conf 显式配置白名单：
   ```ini
   upgrade_allowed_hosts=artifacts.example.com,10.0.0.5
   ```
   名单内主机允许内网地址（同时不再经过环境 HTTP 代理）。
4. 二进制落盘后会校验 **ELF / PE / Mach-O** 头与当前 `GOOS/GOARCH`，错架构制品会被拒绝。
5. 预检要求：`core_version` 与制品 `compatible_core_version` 一致；在线 npc 版本一致；**当前运行版本制品必须在库**（用于回滚）。
6. 启动升级：默认异步；可设 canary 与观察秒数；单批建议 ≤10 台。
7. nps 侧本地备份：`.bak-ext` / `.bak-ext.old`；回滚失败会告警并写 `alerts`。
8. Windows：运行中的 exe 不可覆盖，会写 `xnps-update.exe`，需人工替换并重启。

## 11. 发布、回滚与备份

### 11.1 发布流程

```bash
cd /var/www/xnps-admin
sudo -u www-data php artisan down --retry=60
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan queue:restart
sudo systemctl reload php8.3-fpm
sudo -u www-data php artisan up
```

### 11.2 备份

| 对象 | 方式 |
|---|---|
| 数据库 | `deploy/backup.sh`（mysqldump --single-transaction --routines） |
| 制品库 | `deploy/backup.sh` 打包 `storage/app` |
| 配置密钥 | `.env`、`APP_KEY` 离线保管（`INCLUDE_ENV=1` 可纳入备份） |
| nps 侧 | `conf/nps.conf`、`conf/*.pem`、`conf/acme_account.key`（`NPS_CONF_DIR=... deploy/backup.sh`） |

建议每日备份、异地保存；恢复后执行 `config:cache` 与 `queue:restart`。脚本细节见 §17。

### 11.3 回滚

- 应用：保留上一版本目录/软链，切回后 `config:cache` + `queue:restart`；数据库迁移不可逆时使用备份恢复。
- nps：使用制品库旧版本或 nps 本地 `.bak-ext`。

## 12. 运维与故障排查

### 12.1 常用命令

```bash
php artisan schedule:list
php artisan queue:monitor nps-sync,nps-provision,nps-upgrade
php artisan queue:failed
php artisan queue:retry all
php artisan queue:restart
curl -fsS http://127.0.0.1:8000/healthz | jq .        # I3：DB/缓存/队列/storage
curl -fsS -H "Authorization: Bearer $NPS_METRICS_TOKEN" http://127.0.0.1:8000/metrics | head
tail -f storage/logs/laravel-$(date +%F).log          # 非 JSON 通道时
tail -f storage/logs/laravel-$(date +%F).json         # LOG_CHANNEL=json 时（RotatingFileHandler）
```

### 12.2 故障排查

| 现象 | 排查 |
|---|---|
| `/admin` 空白 | `public/build` 是否存在；`ASSET_URL=/`；浏览器控制台资源 404/CORS |
| API 401 | token 过期（12h）或未登录；重新登录 |
| API 403 | 用户非 `is_admin` |
| 502/504 | PHP-FPM 未启动；`fastcgi_read_timeout`/`request_terminate_timeout` 过短（全量对账、证书操作） |
| 队列不执行 | worker 是否运行；队列名是否包含 `nps-sync/nps-provision/nps-upgrade` |
| 同步延迟告警 | nps 不可达、时钟漂移、worker 停止、`retry_after` 过短导致重复执行 |
| 支付回调 400 | 验签失败、`APP_ID`/商户号不匹配、金额不一致、平台证书/APIv3 Key 错误 |
| 支付后余额未变 | 检查 `payments.status`、`wallet_transactions`、回调是否到达 |
| ACME 挑战 404 | 域名未解析到该 nps；`http_proxy_port` 非 80 且未做 80 映射；防火墙 |
| ACME 报 directory 不允许 | 仅允许 Let's Encrypt 生产/测试目录（防 SSRF） |
| 启用 HTTPS 后失联 | 按 §9 自锁恢复流程关闭 `web_open_ssl` |
| 升级 Run 一直 running | worker 超时/中断；检查 `failed_jobs`，必要时将 Run 标记 failed 后重试 |
| 升级预检失败 | 旧版本制品未入库；版本签名不是 `{core}-ext{ext}`；core 版本不一致 |
| 升级下载被拒绝 | 制品 URL 非 HTTPS、重定向、或解析到内网/回环地址；内网制品库需配置 `upgrade_allowed_hosts` |
| 升级提示架构不匹配 | 制品与目标服务器 `GOOS/GOARCH` 不一致（ELF/PE/Mach-O 头校验） |
| `/ext/cert` 报路径错误 | `web_cert_file` / `web_key_file` 不在 nps `conf/` 目录内 |
| 管理 API 401/403 | token 过期或 ability 不匹配（admin/portal），重新登录 |
| `jobs` 表持续增长 | 检查是否有多个 scheduler（`schedule:work` 与 cron 并存）；Job 的 `ShouldBeUnique` 会丢弃重复派发，确认无重复部署 |
| `/healthz` 返回 503 | 对应 `checks`（database/cache/queue/storage）失败；看 `checks.*.error` 与日志中的 `request_id` |
| `/metrics` 返回 403 | 设置了 `NPS_METRICS_TOKEN`，抓取端需带 `Authorization: Bearer` 或 `X-Metrics-Token` |
| 告警未收到 webhook | `failed_jobs` 是否堆积；`alerts.notify_error`/`notify_attempts`；webhook 5 次退避（10s→10min）后进入 failed |
| 告警不再产生 | 是否命中静默（`/api/admin/monitor/silences`）、去抖窗口 `NPS_ALERT_DEBOUNCE_MINUTES` 未过、或条件已自动 resolve |
| 日志不是 JSON | 生产应设 `LOG_CHANNEL=json`；检查 `.env` 覆盖了默认通道 |
| compose `init` 反复失败 | nps 尚未健康或 `NPS_AUTH_KEY` 与 nps 容器 `/conf/nps.conf` 不一致；`docker compose logs init nps` |

## 13. 安全基线

- `APP_ENV=production`、`APP_DEBUG=false`；`/admin` 与 `/api/admin/*` 建议限制 VPN/办公网 IP。
- 管理员登录限流 5 次/分钟；`/api/admin` 与 `/api/nps` 限流 120 次/分钟；管理 token 12h、门户 token 30 天，且按 ability 隔离（`admin` / `portal`）。
- 门户注册密码策略：至少 10 位且包含字母与数字。
- `NPS_REQUIRE_HTTPS=true` 时，`/ext/cert`、`/ext/config`、`/ext/restart`、`/ext/upgrade`、`/ext/acme/issue` 在明文 HTTP 下会被直接拒绝；默认 false 仅写 warning，生产建议开启。
- 证书与私钥只允许写入 nps 的 `conf/` 目录（`/ext/cert` 会拒绝目录外路径）。
- 制品下载 token 默认 7 天过期，支持轮换；下载为流式输出，需限制 VPN/来源。
- 管理员使用强密码；`nps:admin` 自动生成密码仅显示一次。
- 全站 HTTPS；`SESSION_ENCRYPT=true`、`SESSION_SECURE_COOKIE=true`。
- `.env`、支付私钥、平台证书权限 600，属主 `www-data`；禁止入库。
- 数据库账号最小权限；生产关闭 mock 支付。
- 审计日志默认保留 90 天；请求/响应中的私钥、密码、auth_key、vkey 已脱敏。
- 定期更新 PHP/Laravel/系统依赖；发布前执行 `composer audit` 与 `govulncheck ./server/... ./web/... ./lib/... ./cmd/nps`（当前调用路径 0 漏洞），SBOM 见 `sbom/xnps.cdx.json`（可用 `syft scan dir:. -o cyclonedx-json=...` 重建）。
- 管理端与被管 nps 之间建议使用 CA 证书并保持 `tls_verify=true`；启用 HTTPS 默认校验证书链，自签场景需在启用动作中显式关闭并会写 warning 日志。
- `/healthz` 公开（仅返回检查项状态，不含错误详情），建议只允许 LB/VPN 访问；`/metrics` 请设置 `NPS_METRICS_TOKEN` 并限制来源。
- `LOG_CHANNEL=json` 时日志仍含请求路径与 request_id，勿记录密码/密钥；慢查询日志不记录绑定参数。

## 14. 可观测性（I3）

### 14.1 健康检查 `/healthz`

```bash
curl -fsS http://127.0.0.1:8000/healthz
# {"status":"ok","checks":{"database":{"ok":true,"duration_ms":1},...},"time":"..."}
```

- 四项探针：`database`（`select 1`）、`cache`（写入/回读/删除）、`queue`（`Queue::size()` 驱动连通性）、`storage`（`storage/` 可写）。
- 全部通过返回 **200**；任一失败返回 **503** 且 `status=degraded`；失败只回显异常类名，详情写日志（带 `request_id`）。
- 适合作为 Nginx upstream 健康检查或容器 healthcheck。

### 14.2 Prometheus `/metrics`

设置 `NPS_METRICS_TOKEN` 后需带 Bearer：

```yaml
# prometheus.yml
scrape_configs:
  - job_name: xnps-admin
    metrics_path: /metrics
    authorization:
      credentials: <NPS_METRICS_TOKEN>
    static_configs:
      - targets: ['127.0.0.1:8000']
```

| 指标 | 含义 |
|---|---|
| `nps_server_up{server}` | 纳管 nps 最近一次探活（1/0） |
| `nps_sync_lag_seconds{server}` | 距上次成功同步的秒数（-1=从未同步） |
| `nps_server_clients_online{server}` | 本地镜像在线 client 数 |
| `nps_queue_jobs{queue}` | 各队列积压（-1=读取失败） |
| `nps_queue_failed_jobs_total` | `failed_jobs` 行数 |
| `nps_api_requests_window{window}` / `nps_api_errors_window` / `nps_api_error_ratio` | 最近窗口的 nps API 调用量/失败量/错误率 |
| `nps_billing_settle_duration_seconds{stat=avg\|max}` | 最近 24h 账单结算耗时 |
| `nps_billing_settled_total` | 最近 24h 结算账单数 |
| `nps_upgrade_runs{status}` | 升级 Run 各状态数量 |
| `nps_alerts_open{level}` | 未解决告警数 |

Grafana 推荐面板（JSON 见 `deploy/grafana/xnps-dashboard.json`）：

```promql
max by (server) (nps_sync_lag_seconds)
sum by (queue) (nps_queue_jobs)
nps_api_error_ratio
max(nps_billing_settle_duration_seconds{stat="max"})
sum(nps_upgrade_runs{status="failed"})
sum(nps_alerts_open)
```

### 14.3 结构化日志与追踪

- 生产默认 `LOG_CHANNEL=json`（`APP_ENV=production` 且未显式设置时）；本地开发保持 `stack/single`。
- 每个 HTTP 请求生成/透传 `X-Request-Id`（8–64 位 `[A-Za-z0-9._-]`），写入日志上下文并回显响应头；Nginx 可用 `$request_id` 透传。
- 慢查询：`NPS_SLOW_QUERY_MS=500` 时记录 `sql`/`time_ms`/`connection`（不含绑定参数）。
- 同步/指标/对账 Job 自动写入 `server_id` 与 `job` 上下文，结合审计表可还原“哪台服务器、哪一跳”失败。
- 日志示例：

```bash
tail -f storage/logs/laravel-$(date +%F).json | jq -c '{time:.datetime, level:.level_name, msg:.message, request_id:.context.request_id}'
```

### 14.4 告警运维 API

| 方法与路径 | 说明 |
|---|---|
| `GET /api/admin/monitor/alerts?status=open&level=critical&server_id=1` | 告警分页列表 |
| `POST /api/admin/monitor/alerts/{id}/acknowledge` | 确认（值班认领） |
| `POST /api/admin/monitor/alerts/{id}/resolve` | 手动解决 |
| `POST /api/admin/monitor/alerts/silence` | 静默：`{server_id?,type?,minutes,reason?}` |
| `GET /api/admin/monitor/silences` / `DELETE /api/admin/monitor/silences/{id}` | 静默列表/取消 |

- 去抖：同 server+type 在 `NPS_ALERT_DEBOUNCE_MINUTES`（默认 60）内且未 resolve 不重复；条件恢复时服务器级告警自动 resolve。
- webhook：`NPS_ALERT_WEBHOOK_URL`；投递 Job 指数退避（10s/30s/2min/5min/10min，共 5 次），`alerts.notify_attempts/notify_error` 记录结果。

## 15. Docker Compose 开发栈

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f init     # 迁移 + 管理员 + 纳管 nps
```

- 服务：`db`（MySQL 8）、`nps`（Dockerfile.nps）、`init`（一次性初始化）、`admin`（:8000）、`queue`、`scheduler`。
- 默认值均为**开发口令**：`APP_KEY`、`DB_PASSWORD`、`ADMIN_PASSWORD=admin12345`、`NPS_AUTH_KEY=xnps-docker-dev-key`；生产必须通过环境变量覆盖。
- nps 配置位于 `nps-conf` 持久卷（`/conf`）；启动脚本用 `NPS_AUTH_KEY` / `NPS_WEB_PASSWORD` 覆盖 `nps.conf` 对应项，其余配置可 `docker compose exec nps vi /conf/nps.conf` 后重启。
- 重置：`docker compose down -v`（会删除数据库与制品卷）。

## 16. systemd 单元

`deploy/systemd/` 提供三个模板（替换路径/用户后安装）：

```bash
sudo cp deploy/systemd/xnps.service /etc/systemd/system/
sudo cp deploy/systemd/xnps-admin-scheduler.service /etc/systemd/system/
sudo cp deploy/systemd/xnps-admin-queue@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now xnps xnps-admin-scheduler
sudo systemctl enable --now xnps-admin-queue@nps-sync xnps-admin-queue@nps-provision xnps-admin-queue@nps-upgrade
```

- `schedule:work` 必须单实例；不要同时启用 cron `schedule:run`。
- queue 实例名即队列名；`--timeout=900` 需与 `DB_QUEUE_RETRY_AFTER/REDIS_QUEUE_RETRY_AFTER` 匹配。
- 与 Supervisor 二选一，不要重复拉起。

## 17. 备份与恢复

```bash
# 每日备份（数据库 + storage/app；可选 nps conf 与 .env）
APP_DIR=/var/www/xnps-admin BACKUP_DIR=/var/backups/xnps RETENTION_DAYS=14 \
NPS_CONF_DIR=/opt/xnps/conf INCLUDE_ENV=1 /var/www/xnps-admin/deploy/backup.sh

# crontab（备份脚本自带保留期清理）
15 3 * * * APP_DIR=/var/www/xnps-admin BACKUP_DIR=/var/backups/xnps /var/www/xnps-admin/deploy/backup.sh >>/var/log/xnps-backup.log 2>&1

# 恢复（先停 queue/scheduler；脚本会校验 sha256sums.txt）
sudo systemctl stop 'xnps-admin-queue@*' xnps-admin-scheduler
RESTORE_CONFIRM=yes APP_DIR=/var/www/xnps-admin /var/www/xnps-admin/deploy/restore.sh /var/backups/xnps/20261029T000000Z
sudo systemctl start 'xnps-admin-queue@*' xnps-admin-scheduler
```

- MySQL 凭据从 `.env` 读取，临时 client 配置 600 权限，不出现在进程参数中。
- SQLite 部署走 `.backup`（失败回退文件复制）。
- 恢复后执行 `php artisan migrate --force && php artisan queue:restart`。

## 18. 已知限制

- 支付：未实现退款、发票、支付渠道对账报表。
- 监控：已提供 `/metrics` 与 webhook 通知，但未集成 Alertmanager/PagerDuty；webhook 为简单 JSON POST。
- 同步任务推荐常驻 `schedule:work`；cron `schedule:run` 也可运行亚分钟事件，但需 `flock` 防进程重叠。
- 管理 API 已内置限流；仍建议在 Nginx/WAF 层叠加来源限制。
- 未提供多租户强隔离与 HA 部署方案；单管理端故障会影响纳管操作，但不影响 nps 数据面转发。

## 附录 A：生产 `.env` 完整模板

```ini
APP_NAME="XNPS Admin"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://admin.example.com
ASSET_URL=/
APP_LOCALE=zh_CN
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xnps_admin
DB_USERNAME=xnps_admin
DB_PASSWORD=...

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=...
REDIS_PORT=6379
REDIS_QUEUE_RETRY_AFTER=3700

SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

FILESYSTEM_DISK=local

# 支付
PAYMENT_DEFAULT_CHANNEL=alipay
PAYMENT_MOCK_ENABLED=false
PAYMENT_EXPIRE_MINUTES=30
ALIPAY_ENABLED=false
ALIPAY_APP_ID=
ALIPAY_PRIVATE_KEY=
ALIPAY_PUBLIC_KEY=
ALIPAY_GATEWAY=https://openapi.alipay.com/gateway.do
ALIPAY_NOTIFY_URL=https://admin.example.com/api/payments/alipay/notify
WECHAT_PAY_ENABLED=false
WECHAT_PAY_APP_ID=
WECHAT_PAY_MCH_ID=
WECHAT_PAY_SERIAL_NO=
WECHAT_PAY_PRIVATE_KEY=
WECHAT_PAY_API_V3_KEY=
WECHAT_PAY_PLATFORM_CERT=
WECHAT_PAY_NOTIFY_URL=https://admin.example.com/api/payments/wechat/notify

# 证书
NPS_ACME_MODE=
NPS_ACME_EMAIL=
NPS_ACME_DIRECTORY_URL=
NPS_ACME_CERTD_URL=
NPS_ACME_CERTD_TOKEN=
NPS_CERTD_DEPLOY_URL=
NPS_CERTD_TOKEN=

# 可观测性与告警（I3）
NPS_ALERT_WEBHOOK_URL=
NPS_ALERT_DEBOUNCE_MINUTES=60
NPS_METRICS_TOKEN=
NPS_SLOW_QUERY_MS=500
```

## 附录 B：验收命令速查

```bash
# 健康（I3：DB/缓存/队列/storage）
curl -fsS https://admin.example.com/healthz

# Prometheus 指标（配置了 NPS_METRICS_TOKEN 时）
curl -fsS -H "Authorization: Bearer $NPS_METRICS_TOKEN" https://admin.example.com/metrics | head

# 管理员登录
curl -s -X POST https://admin.example.com/api/admin/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"..."}'

# 仪表盘（带 token）
curl -s https://admin.example.com/api/admin/dashboard \
  -H "Authorization: Bearer <token>" -H 'Accept: application/json'

# 队列与调度
php artisan schedule:list
php artisan queue:monitor nps-sync,nps-provision,nps-upgrade

# 数据体量
mysql -e "SELECT COUNT(*) FROM xnps_admin.nps_clients;
          SELECT COUNT(*) FROM xnps_admin.nps_tunnels;
          SELECT COUNT(*) FROM xnps_admin.jobs;"
```
