<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\User;
use App\Services\Payments\Drivers\AlipayDriver;
use App\Services\Payments\Drivers\WechatDriver;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_payment_credits_wallet_once(): void
    {
        config(['payments.mock_enabled' => true, 'payments.default_channel' => 'mock']);
        $user = User::factory()->create();

        /** @var PaymentService $service */
        $service = app(PaymentService::class);
        $payment = $service->create($user, 1234, 'mock', 'test recharge');

        $this->assertSame('pending', $payment->status);
        $this->assertSame('mock://pay/'.$payment->out_trade_no, $payment->qr_code);

        $this->assertTrue($service->markPaid($payment, 'MOCK-1', ['mock' => true]));
        $this->assertFalse($service->markPaid($payment->fresh(), 'MOCK-2', []), 'second markPaid must be idempotent');

        $this->assertSame(1234, (int) $user->wallet()->first()->balance_cents);
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_bill_payment_marks_bill_paid_without_wallet_credit(): void
    {
        config(['payments.mock_enabled' => true]);
        $user = User::factory()->create();
        $bill = Bill::create([
            'user_id' => $user->id,
            'period' => '2026-09',
            'plan_fee_cents' => 800,
            'used_bytes' => 0,
            'included_bytes' => 0,
            'overage_bytes' => 0,
            'overage_fee_cents' => 0,
            'total_fee_cents' => 800,
            'status' => 'pending',
        ]);

        $service = app(PaymentService::class);
        $payment = $service->create($user, 800, 'mock', 'bill', $bill->id);
        $service->markPaid($payment, 'MOCK-BILL');

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertNotNull($bill->fresh()->paid_at);
        $this->assertNull($user->wallet()->first(), 'bill payment must not credit wallet');
    }

    public function test_alipay_notify_signature_and_amount_validation(): void
    {
        [$privateKey, $publicKey] = $this->rsaKeyPair();
        config(['payments.alipay' => [
            'enabled' => true,
            'app_id' => 'app-123',
            'private_key' => $privateKey,
            'public_key' => $publicKey,
            'gateway' => 'https://example.invalid/gateway.do',
            'notify_url' => '',
        ]]);

        $params = [
            'app_id' => 'app-123',
            'out_trade_no' => 'XN20260101000000ABCDEFGH',
            'trade_no' => '2026010122001',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '12.34',
            'timestamp' => '2026-01-01 00:00:00',
            'sign_type' => 'RSA2',
        ];
        $params['sign'] = $this->alipaySign($params, $privateKey);

        $data = (new AlipayDriver())->verify(Request::create('/api/payments/alipay/notify', 'POST', $params));

        $this->assertTrue($data['paid']);
        $this->assertSame(1234, $data['amount_cents']);
        $this->assertSame('XN20260101000000ABCDEFGH', $data['out_trade_no']);

        $params['sign'] = 'invalid-signature';
        $this->expectException(PaymentException::class);
        (new AlipayDriver())->verify(Request::create('/api/payments/alipay/notify', 'POST', $params));
    }

    public function test_wechat_notify_signature_and_decryption(): void
    {
        [$merchantPrivate, $platformPublic] = $this->rsaKeyPair();
        $apiKey = str_repeat('k', 32);
        config(['payments.wechat' => [
            'enabled' => true,
            'app_id' => 'wx-app',
            'mch_id' => 'mch-1',
            'serial_no' => 'serial-1',
            'private_key' => $merchantPrivate,
            'api_v3_key' => $apiKey,
            'platform_cert' => $platformPublic,
            'api_base' => 'https://example.invalid',
            'notify_url' => '',
        ]]);

        $plain = json_encode([
            'out_trade_no' => 'XN20260101000000ABCDEFGH',
            'transaction_id' => '4200001',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 2500, 'currency' => 'CNY'],
        ]);
        $nonce = 'abcdefghijkl';
        $aad = 'transaction';
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $apiKey, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        $body = json_encode([
            'id' => 'evt-1',
            'resource' => [
                'ciphertext' => base64_encode($cipher.$tag),
                'nonce' => $nonce,
                'associated_data' => $aad,
            ],
        ]);
        $timestamp = (string) time();
        $headerNonce = 'nonce-123456';
        $message = $timestamp."\n".$headerNonce."\n".$body."\n";
        openssl_sign($message, $signature, $merchantPrivate, OPENSSL_ALGO_SHA256);

        $request = Request::create(
            '/api/payments/wechat/notify',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_WECHATPAY_TIMESTAMP' => $timestamp,
                'HTTP_WECHATPAY_NONCE' => $headerNonce,
                'HTTP_WECHATPAY_SIGNATURE' => base64_encode($signature),
            ],
            $body,
        );

        $data = (new WechatDriver())->verify($request);
        $this->assertTrue($data['paid']);
        $this->assertSame(2500, $data['amount_cents']);

        $tampered = str_replace('evt-1', 'evt-2', $body);
        $badRequest = Request::create(
            '/api/payments/wechat/notify', 'POST', [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_WECHATPAY_TIMESTAMP' => $timestamp,
                'HTTP_WECHATPAY_NONCE' => $headerNonce,
                'HTTP_WECHATPAY_SIGNATURE' => base64_encode($signature),
            ],
            $tampered,
        );
        $this->expectException(PaymentException::class);
        (new WechatDriver())->verify($badRequest);
    }

    // ------------------------------------------------------------------

    /** @return array{0: string, 1: string} */
    private function rsaKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key, 'openssl key generation failed');
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);

        return [$privatePem, $details['key']];
    }

    private function alipaySign(array $params, string $privateKey): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[] = $key.'='.$value;
        }
        openssl_sign(implode('&', $pairs), $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
