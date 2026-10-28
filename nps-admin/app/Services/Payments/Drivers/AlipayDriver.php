<?php

namespace App\Services\Payments\Drivers;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentDriver;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 支付宝当面付（alipay.trade.precreate）扫码支付。
 *
 * 签名：RSA2（SHA256withRSA），签名串为参数按 key 升序、剔除 sign/sign_type/空值后 k=v& 拼接。
 * 回调：Verify 使用支付宝公钥验签后校验 app_id / 金额 / 交易状态。
 */
class AlipayDriver implements PaymentDriver
{
    public function channel(): string
    {
        return Payment::CHANNEL_ALIPAY;
    }

    public function create(Payment $payment): array
    {
        $cfg = (array) config('payments.alipay');
        if (empty($cfg['enabled'])) {
            throw new PaymentException('alipay channel is not enabled');
        }
        $appId = (string) ($cfg['app_id'] ?? '');
        $gateway = (string) ($cfg['gateway'] ?? '');
        $notifyUrl = (string) ($cfg['notify_url'] ?: config('app.url').'/api/payments/alipay/notify');
        if ($appId === '' || $gateway === '') {
            throw new PaymentException('alipay app_id / gateway is not configured');
        }

        $biz = [
            'out_trade_no' => $payment->out_trade_no,
            'total_amount' => number_format($payment->amount_cents / 100, 2, '.', ''),
            'subject' => $payment->subject,
            'product_code' => 'FACE_TO_FACE_PAYMENT',
            'timeout_express' => config('payments.expire_minutes', 30).'m',
        ];
        $params = [
            'app_id' => $appId,
            'method' => 'alipay.trade.precreate',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => now('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $notifyUrl,
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $this->sign($this->signContent($params), PaymentKeys::orFail((string) $cfg['private_key'], 'ALIPAY_PRIVATE_KEY'));

        $resp = Http::asForm()->timeout(20)->post($gateway, $params);
        if (! $resp->successful()) {
            throw new PaymentException('alipay gateway HTTP '.$resp->status());
        }
        $body = $resp->body();
        $json = json_decode($body, true);
        if (! is_array($json)) {
            throw new PaymentException('alipay gateway returned non-json response');
        }
        $this->verifyResponse($body, $json, (string) $cfg['public_key']);

        $node = (array) ($json['alipay_trade_precreate_response'] ?? []);
        $code = (string) ($node['code'] ?? '');
        if ($code !== '10000' || empty($node['qr_code'])) {
            throw new PaymentException('alipay precreate failed: '.($node['sub_msg'] ?? $node['msg'] ?? 'unknown'));
        }

        return ['qr_code' => (string) $node['qr_code'], 'pay_url' => null];
    }

    public function verify(Request $request): array
    {
        $params = $request->all();
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            throw new PaymentException('alipay notify missing sign');
        }
        $publicKey = PaymentKeys::orFail((string) config('payments.alipay.public_key'), 'ALIPAY_PUBLIC_KEY');
        if (! $this->verifySign($this->signContent($params), $sign, $publicKey)) {
            throw new PaymentException('alipay notify signature verification failed');
        }

        $appId = (string) config('payments.alipay.app_id');
        if ($appId !== '' && (string) ($params['app_id'] ?? '') !== $appId) {
            throw new PaymentException('alipay notify app_id mismatch');
        }

        $status = (string) ($params['trade_status'] ?? '');
        $paid = in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);
        $amount = (int) round(((float) ($params['total_amount'] ?? 0)) * 100);
        if ($paid && $amount <= 0) {
            throw new PaymentException('alipay notify invalid total_amount');
        }

        Log::info('alipay notify', ['out_trade_no' => $params['out_trade_no'] ?? null, 'trade_status' => $status]);

        return [
            'out_trade_no' => (string) ($params['out_trade_no'] ?? ''),
            'transaction_id' => isset($params['trade_no']) ? (string) $params['trade_no'] : null,
            'amount_cents' => $amount,
            'paid' => $paid,
            'payload' => $this->redact($params),
        ];
    }

    // ------------------------------------------------------------------
    // 签名 / 验签
    // ------------------------------------------------------------------

    private function signContent(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $pairs[] = $key.'='.$value;
        }

        return implode('&', $pairs);
    }

    private function sign(string $content, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new PaymentException('alipay private key is invalid');
        }
        $signature = '';
        if (! openssl_sign($content, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PaymentException('alipay sign failed: '.openssl_error_string());
        }

        return base64_encode($signature);
    }

    private function verifySign(string $content, string $sign, string $publicKeyPem): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new PaymentException('alipay public key is invalid');
        }

        return openssl_verify($content, base64_decode($sign), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function verifyResponse(string $rawBody, array $json, string $publicKey): void
    {
        if ($publicKey === '' || empty($json['sign'])) {
            return;
        }
        $content = $this->extractNode($rawBody, 'alipay_trade_precreate_response');
        if ($content === null) {
            throw new PaymentException('cannot extract alipay response content');
        }
        if (! $this->verifySign($content, (string) $json['sign'], PaymentKeys::load($publicKey))) {
            throw new PaymentException('alipay response signature verification failed');
        }
    }

    private function extractNode(string $body, string $key): ?string
    {
        $needle = '"'.$key.'":';
        $pos = strpos($body, $needle);
        if ($pos === false) {
            return null;
        }
        $start = strpos($body, '{', $pos);
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escaped = false;
        $len = strlen($body);
        for ($i = $start; $i < $len; $i++) {
            $ch = $body[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($body, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function redact(array $params): array
    {
        unset($params['sign']);

        return $params;
    }
}
