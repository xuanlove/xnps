<?php

namespace App\Services\Payments\Drivers;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentDriver;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 微信支付 API v3（Native 扫码）。
 *
 * 请求签名：SHA256-RSA，签名串 = METHOD\nPATH\nTIMESTAMP\nNONCE\nBODY\n
 * 回调：用平台证书验签（timestamp\nnonce\nbody\n），再用 APIv3 Key 做 AES-256-GCM 解密 resource。
 */
class WechatDriver implements PaymentDriver
{
    public function channel(): string
    {
        return Payment::CHANNEL_WECHAT;
    }

    public function create(Payment $payment): array
    {
        $cfg = (array) config('payments.wechat');
        if (empty($cfg['enabled'])) {
            throw new PaymentException('wechat channel is not enabled');
        }
        foreach (['app_id', 'mch_id', 'serial_no', 'private_key'] as $key) {
            if (empty($cfg[$key])) {
                throw new PaymentException("wechat pay config missing: {$key}");
            }
        }

        $path = '/v3/pay/transactions/native';
        $payload = [
            'appid' => (string) $cfg['app_id'],
            'mchid' => (string) $cfg['mch_id'],
            'description' => Str::limit($payment->subject, 120, ''),
            'out_trade_no' => $payment->out_trade_no,
            'notify_url' => (string) ($cfg['notify_url'] ?: config('app.url').'/api/payments/wechat/notify'),
            'amount' => ['total' => $payment->amount_cents, 'currency' => 'CNY'],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $authorization = $this->authorization('POST', $path, $body, $cfg);

        $resp = Http::withHeaders([
            'Authorization' => $authorization,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->withBody($body, 'application/json')->timeout(20)->post(rtrim((string) $cfg['api_base'], '/').$path);

        if (! $resp->successful()) {
            Log::warning('wechat native order failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new PaymentException('wechat native order failed: HTTP '.$resp->status());
        }
        $codeUrl = (string) ($resp->json('code_url') ?? '');
        if ($codeUrl === '') {
            throw new PaymentException('wechat native order missing code_url');
        }

        return ['qr_code' => $codeUrl, 'pay_url' => null];
    }

    public function verify(Request $request): array
    {
        $cfg = (array) config('payments.wechat');
        $timestamp = (string) $request->header('Wechatpay-Timestamp', '');
        $nonce = (string) $request->header('Wechatpay-Nonce', '');
        $signature = (string) $request->header('Wechatpay-Signature', '');
        $rawBody = (string) $request->getContent();
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            throw new PaymentException('wechat notify missing signature headers');
        }
        $platformCert = PaymentKeys::orFail((string) ($cfg['platform_cert'] ?? ''), 'WECHAT_PAY_PLATFORM_CERT');
        $message = $timestamp."\n".$nonce."\n".$rawBody."\n";
        $publicKey = openssl_pkey_get_public($platformCert);
        if ($publicKey === false) {
            throw new PaymentException('wechat platform certificate is invalid');
        }
        if (openssl_verify($message, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new PaymentException('wechat notify signature verification failed');
        }

        $json = json_decode($rawBody, true);
        if (! is_array($json)) {
            throw new PaymentException('wechat notify body is not json');
        }
        $resource = (array) ($json['resource'] ?? []);
        $plain = $this->decryptResource($resource, (string) ($cfg['api_v3_key'] ?? ''));
        $data = json_decode($plain, true);
        if (! is_array($data)) {
            throw new PaymentException('wechat notify resource decrypt failed');
        }

        $state = (string) ($data['trade_state'] ?? '');
        $amount = (int) ($data['amount']['total'] ?? 0);
        Log::info('wechat notify', ['out_trade_no' => $data['out_trade_no'] ?? null, 'trade_state' => $state]);

        return [
            'out_trade_no' => (string) ($data['out_trade_no'] ?? ''),
            'transaction_id' => isset($data['transaction_id']) ? (string) $data['transaction_id'] : null,
            'amount_cents' => $amount,
            'paid' => $state === 'SUCCESS',
            'payload' => [
                'trade_state' => $state,
                'amount' => $amount,
                'out_trade_no' => $data['out_trade_no'] ?? null,
            ],
        ];
    }

    // ------------------------------------------------------------------

    private function authorization(string $method, string $path, string $body, array $cfg): string
    {
        $timestamp = (string) time();
        $nonce = Str::random(32);
        $message = $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$body."\n";
        $key = openssl_pkey_get_private(PaymentKeys::orFail((string) $cfg['private_key'], 'WECHAT_PAY_PRIVATE_KEY'));
        if ($key === false) {
            throw new PaymentException('wechat merchant private key is invalid');
        }
        $signature = '';
        if (! openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PaymentException('wechat request sign failed: '.openssl_error_string());
        }

        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $cfg['mch_id'], $nonce, base64_encode($signature), $timestamp, $cfg['serial_no'],
        );
    }

    private function decryptResource(array $resource, string $apiV3Key): string
    {
        if ($apiV3Key === '' || strlen($apiV3Key) !== 32) {
            throw new PaymentException('wechat api_v3_key must be 32 bytes');
        }
        $ciphertext = base64_decode((string) ($resource['ciphertext'] ?? ''));
        if (strlen($ciphertext) <= 16) {
            throw new PaymentException('wechat notify ciphertext is invalid');
        }
        $tag = substr($ciphertext, -16);
        $data = substr($ciphertext, 0, -16);
        $nonce = (string) ($resource['nonce'] ?? '');
        $aad = (string) ($resource['associated_data'] ?? '');
        $plain = openssl_decrypt($data, 'aes-256-gcm', $apiV3Key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($plain === false) {
            throw new PaymentException('wechat notify decrypt failed');
        }

        return $plain;
    }
}
