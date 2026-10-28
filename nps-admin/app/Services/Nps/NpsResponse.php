<?php

namespace App\Services\Nps;

/**
 * nps 三种响应格式的统一封装（DESIGN.md §2.2）：
 *   {"status":1,"msg":...,"data":...}  —— add/edit/del/start/stop 等
 *   {"rows":[...],"total":n}           —— list 类端点
 *   {"code":1,"data":{...}}            —— getclient/getonetunnel/gethost
 */
class NpsResponse
{
    public function __construct(
        public readonly bool $ok,
        public readonly mixed $data = null,
        public readonly string $msg = '',
        public readonly ?int $total = null,
        public readonly array $raw = [],
        public readonly ?int $statusCode = null,
    ) {}

    /**
     * @throws NpsException 失败时抛出（含上下文）
     */
    public function orFail(string $action): static
    {
        if (! $this->ok) {
            throw new NpsException(
                sprintf('nps API %s failed: %s', $action, $this->msg ?: 'unknown error'),
                ['raw' => $this->raw, 'status_code' => $this->statusCode],
            );
        }

        return $this;
    }

    public static function fromArray(array $json, ?int $statusCode): self
    {
        // 格式一：{"status": 1|0, "msg": ..., "data": ...}
        if (array_key_exists('status', $json)) {
            $ok = (int) $json['status'] === 1;

            return new self(
                ok: $ok,
                data: $json['data'] ?? null,
                msg: (string) ($json['msg'] ?? ''),
                raw: $json,
                statusCode: $statusCode,
            );
        }
        // 格式二：{"rows": [...], "total": n}
        if (array_key_exists('rows', $json)) {
            return new self(
                ok: true,
                data: $json['rows'] ?? [],
                total: (int) ($json['total'] ?? 0),
                raw: $json,
                statusCode: $statusCode,
            );
        }
        // 格式三：{"code": 1|0, "data": ...}
        if (array_key_exists('code', $json)) {
            $ok = (int) $json['code'] === 1;

            return new self(
                ok: $ok,
                data: $json['data'] ?? null,
                msg: $ok ? '' : 'not found',
                raw: $json,
                statusCode: $statusCode,
            );
        }

        return new self(ok: false, msg: 'unrecognized response format', raw: $json, statusCode: $statusCode);
    }
}
