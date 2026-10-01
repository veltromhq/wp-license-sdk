<?php

namespace Veltrom\License\Http;

final class Response
{
    /**
     * @param  array<string, mixed>|null  $json
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $json,
        public readonly ?string $requestId = null,
    ) {
    }
}
