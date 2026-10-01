<?php

namespace Veltrom\License\Testing;

/**
 * Signs entitlement tokens with a throwaway Ed25519 key so a plugin's test
 * suite can stage licensed, unlicensed, expired-updates and offline states
 * without the licensing server (sdk-wordpress.md "Testing").
 */
final class TokenFactory
{
    public const KID = 'ktest';

    /** @var non-empty-string */
    private string $secretKey;

    private string $publicKey;

    public function __construct(
        private readonly string $issuer = 'https://api.veltrom.com',
        private readonly string $audience = 'sample-plugin-pro',
    ) {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    /**
     * The `public_keys` config entry that verifies these tokens.
     *
     * @return array<string, string>
     */
    public function publicKeys(): array
    {
        return [self::KID => base64_encode($this->publicKey)];
    }

    /**
     * @param  array<string, mixed>  $overrides  merged into the claims (nested arrays replaced)
     */
    public function token(string $installationId, int $now, array $overrides = [], string $kid = self::KID): string
    {
        $claims = array_replace([
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => '01JBQ7X2M4KDPS8ZR3N5V6WQYT',
            'jti' => '01JBQ7X9CFTA2K1M8E4D0RJ5HP',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 14 * 86400,
            'srv' => $now,
            'lic' => ['status' => 'active', 'model' => 'perpetual', 'plan' => 'single-site', 'expires_at' => null, 'updates_until' => gmdate('Y-m-d\TH:i:s\Z', $now + 365 * 86400)],
            'act' => ['id' => '01JBQ7XB5N8YZ2H4T6K9M1P3RS', 'installation_id' => $installationId],
            'ent' => ['pro' => true, 'updates' => true],
            'enf' => 'updates_only',
        ], $overrides);

        $header = self::encode((string) json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $kid]));
        $payload = self::encode((string) json_encode($claims));

        return $header . '.' . $payload . '.' . self::encode(sodium_crypto_sign_detached($header . '.' . $payload, $this->secretKey));
    }

    /**
     * An activate/validate success body around a token.
     *
     * @param  array<string, mixed>  $license
     * @return array<string, mixed>
     */
    public function licenseResponse(string $token, array $license = []): array
    {
        return [
            'success' => true,
            'license' => $license + ['status' => 'active', 'plan' => 'single-site', 'model' => 'perpetual', 'expires_at' => null, 'updates_until' => '2027-10-01T00:00:00Z', 'activation_limit' => 1, 'activations_used' => 1],
            'activation' => ['id' => '01JBQ7XB5N8YZ2H4T6K9M1P3RS', 'status' => 'active', 'environment' => 'production'],
            'entitlements' => ['pro' => true, 'updates' => true],
            'token' => $token,
        ];
    }

    /**
     * A /keys body carrying this factory's key under another kid, for rotation tests.
     *
     * @return array<string, mixed>
     */
    public function jwks(string $kid): array
    {
        return ['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $kid, 'alg' => 'EdDSA', 'x' => self::encode($this->publicKey)]]];
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
