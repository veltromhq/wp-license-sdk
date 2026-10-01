<?php

namespace Veltrom\License\Api;

/**
 * One answer from the licensing API: either the decoded success body, or the
 * code from the error envelope (api-v1.md "Conventions"). Clients branch on
 * the code, never on the message.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly array $data,
        public readonly ?string $errorCode,
        public readonly ?string $requestId,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    public static function from(int $status, ?array $json, ?string $requestId): self
    {
        $json ??= [];

        if ($status >= 200 && $status < 300 && ($json['success'] ?? true) !== false) {
            return new self(true, $status, $json, null, $requestId);
        }

        $code = $json['error']['code'] ?? null;

        return new self(false, $status, $json, is_string($code) ? $code : ($status === 429 ? 'rate_limit_exceeded' : 'server_error'), $requestId);
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429 || $this->errorCode === 'rate_limit_exceeded';
    }
}
