<?php

// 支付渠道配置（支付宝 / 微信支付 v3 / 本地 mock）
// 密钥支持两种写法：直接放 PEM 内容（环境变量中 \n 转义）或放文件路径。

return [

    // 未显式指定时的默认渠道；生产建议 alipay 或 wechat
    'default_channel' => env('PAYMENT_DEFAULT_CHANNEL', 'mock'),

    // mock 渠道仅用于开发/联调，生产环境默认强制关闭
    'mock_enabled' => (bool) env('PAYMENT_MOCK_ENABLED', env('APP_ENV', 'production') !== 'production'),

    // 支付二维码有效期（分钟）
    'expire_minutes' => (int) env('PAYMENT_EXPIRE_MINUTES', 30),

    'alipay' => [
        'enabled' => (bool) env('ALIPAY_ENABLED', false),
        'app_id' => env('ALIPAY_APP_ID', ''),
        'private_key' => env('ALIPAY_PRIVATE_KEY', ''),
        'public_key' => env('ALIPAY_PUBLIC_KEY', ''),
        'gateway' => env('ALIPAY_GATEWAY', 'https://openapi.alipay.com/gateway.do'),
        'notify_url' => env('ALIPAY_NOTIFY_URL', ''),
    ],

    'wechat' => [
        'enabled' => (bool) env('WECHAT_PAY_ENABLED', false),
        'app_id' => env('WECHAT_PAY_APP_ID', ''),
        'mch_id' => env('WECHAT_PAY_MCH_ID', ''),
        'serial_no' => env('WECHAT_PAY_SERIAL_NO', ''),
        'private_key' => env('WECHAT_PAY_PRIVATE_KEY', ''),
        'api_v3_key' => env('WECHAT_PAY_API_V3_KEY', ''),
        // 微信支付平台证书（PEM），用于回调验签
        'platform_cert' => env('WECHAT_PAY_PLATFORM_CERT', ''),
        'api_base' => env('WECHAT_PAY_API_BASE', 'https://api.mch.weixin.qq.com'),
        'notify_url' => env('WECHAT_PAY_NOTIFY_URL', ''),
    ],

];
