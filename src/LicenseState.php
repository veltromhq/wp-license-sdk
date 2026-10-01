<?php

namespace Veltrom\License;

/**
 * What the licence screen shows (sdk-wordpress.md "Admin screen" states),
 * read without touching the network.
 */
final class LicenseState
{
    public const NONE = 'none';
    public const ACTIVE = 'active';
    public const UPDATES_EXPIRED = 'updates_expired';
    public const GRACE = 'grace';
    public const SUSPENDED = 'suspended';
    public const REVOKED = 'revoked';
    public const INVALID_KEY = 'invalid_key';
    public const NEEDS_CONNECTION = 'needs_connection';

    /**
     * @param  array<string, bool>  $entitlements
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $maskedKey,
        public readonly ?string $updatesUntil,
        public readonly ?string $expiresAt,
        public readonly ?int $activationLimit,
        public readonly ?int $activationsUsed,
        public readonly ?int $lastChecked,
        public readonly bool $lastCheckFailed,
        public readonly array $entitlements,
    ) {
    }

    public function hasLicense(): bool
    {
        return $this->status !== self::NONE;
    }
}
