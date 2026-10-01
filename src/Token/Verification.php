<?php

namespace Veltrom\License\Token;

/**
 * The outcome of verifying a stored token without the network.
 */
final class Verification
{
    public const VALID = 'valid';
    public const MALFORMED = 'malformed';
    public const UNKNOWN_KEY = 'unknown_key';
    public const BAD_SIGNATURE = 'bad_signature';
    public const WRONG_AUDIENCE = 'wrong_audience';
    public const NOT_YET_VALID = 'not_yet_valid';
    public const EXPIRED = 'expired';
    public const OTHER_INSTALLATION = 'other_installation';
    public const CLOCK_BEHIND = 'clock_behind';

    /**
     * @param  array<string, mixed>  $claims
     */
    private function __construct(public readonly string $outcome, public readonly array $claims = [])
    {
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function valid(array $claims): self
    {
        return new self(self::VALID, $claims);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function failed(string $outcome, array $claims = []): self
    {
        return new self($outcome, $claims);
    }

    public function isValid(): bool
    {
        return $this->outcome === self::VALID;
    }

    /**
     * @return array<string, bool>
     */
    public function entitlements(): array
    {
        $entitlements = $this->claims['ent'] ?? [];

        return is_array($entitlements) ? array_map(fn ($value) => $value === true, $entitlements) : [];
    }
}
