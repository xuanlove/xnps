<?php

// nps-admin 集中管理平台配置（DESIGN.md §6 三层同步 / §12 Laravel 技术设计）

return [

    // ---- NpsApiClient ----

    // 单次 HTTP 超时（秒）
    'http_timeout' => 15,

    // 失败重试次数（指数退避）
    'http_retries' => 2,

    // ---- 三层同步（DESIGN.md §6.1）----
    // 调度频率固定在 routes/console.php（10s/30s/5min/6h），由常驻
    // `php artisan schedule:work` 驱动；Job 均为一次性且 ShouldBeUnique。

    // /ext/flow 分片总数（DESIGN.md §6.1：16 分片轮转，每 5min 一片）
    'flow_shards' => 16,

    // flow 采集间隔（秒，每片一次；用于分片号推导）
    'flow_interval' => 300,

    // /ext/list 对账分页大小
    'reconcile_page_size' => 500,

    // 远端为空时是否清空本地镜像（保护开关，防 API 异常返回空导致误删）
    'reconcile_delete_on_empty' => true,

    // 镜像批量 upsert 每批行数
    'upsert_chunk' => 1000,

    // ---- 端口池 ----

    // 端口分配预占锁定时长（秒）：超时未确认自动释放
    'port_hold_ttl' => 120,

    // ---- 审计 ----

    // 是否记录全部 API 调用（含读）到 nps_api_logs；写操作始终记录
    'audit_reads' => false,

    // 审计日志保留天数
    'audit_retention_days' => 90,

    // ---- P2 自助门户（DESIGN.md §8）----

    // 注册后自动订阅的默认套餐 id（0 = 不自动订阅）
    'default_plan_id' => 1,

    // 注册赠送余额（分）
    'welcome_credit_cents' => 0,

    // 审批单过期时间（小时）：submitted 超时未审批自动 expired
    'approval_expire_hours' => 72,

    // 自动审批规则（DESIGN.md §8.1，规则集由运营定义）
    'auto_approve' => [
        'enabled' => true,
        'require_active_subscription' => true,  // 有有效套餐
        'require_no_unpaid_bills' => true,       // 无未付账单
        'max_tunnels_within_plan' => true,       // 套餐内隧道数未超
    ],

    // 月度配额超额动作（DESIGN.md §8.3）：
    //   throttle —— 调低 rate_limit 至套餐限速值
    //   disable  —— changestatus=0 踢下线
    //   bill     —— 仅记账，月末结算超额费
    'quota_action' => 'throttle',

    // 超额流量单价（分 / GB）
    'overage_price_cents_per_gb' => 50,

    // 月度结算日（每月 1 号 00:05 由 scheduler 触发）
    'settle_day' => '1',

    // ---- 证书子系统（DESIGN.md §7，阶段 3）----

    'cert' => [
        // 到期前 N 天进入续期窗口（告警 + 自动续触发）
        'renew_days' => 30,
    ],

    // ACME 来源（生产启用：mode=certd 走 certd 签发流水线；builtin 使用
    // 内置 ACME（nps 侧 HTTP-01）；空=仅人工上传）
    'acme' => [
        'mode' => env('NPS_ACME_MODE', ''),
        'email' => env('NPS_ACME_EMAIL', ''),
        // 空=Let's Encrypt 生产目录；可选 staging 目录
        'directory_url' => env('NPS_ACME_DIRECTORY_URL', ''),
        'certd_url' => env('NPS_ACME_CERTD_URL', ''),
        'certd_token' => env('NPS_ACME_CERTD_TOKEN', ''),
    ],

    // certd 部署通道（空=未配置，自动回退 native 直推）
    'certd' => [
        'deploy_trigger_url' => env('NPS_CERTD_DEPLOY_URL', ''),
        'token' => env('NPS_CERTD_TOKEN', ''),
    ],

    // ---- 监控告警（阶段 3）----

    'monitor' => [
        // 采样频率固定在 routes/console.php（每 30s），由 schedule:work 驱动
        // 指标保留天数
        'metrics_retention_days' => 7,

        // 告警阈值
        'alerts' => [
            'cpu_percent' => 90,
            'mem_percent' => 90,
            'sync_lag_minutes' => 5,
        ],

        // 可选 webhook 通知（空=仅落库+日志）
        'webhook_url' => env('NPS_ALERT_WEBHOOK_URL', ''),
    ],
];
