<?php

namespace Veltrom\License;

/**
 * What activate(), deactivate() and validate() report back. The message is
 * already a sentence in the plugin's text domain; never show `code` to a
 * customer (sdk-wordpress.md "Admin screen").
 */
final class Result
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $code,
        public readonly string $message,
    ) {
    }

    public static function ok(string $message = ''): self
    {
        return new self(true, null, $message);
    }

    public static function error(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
