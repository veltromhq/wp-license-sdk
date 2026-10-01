<?php

namespace Veltrom\License\Token;

/**
 * Public keys by kid: the ones embedded in the plugin first, then the last
 * JWKS fetched from /keys (entitlement-token.md "Key rotation").
 */
final class KeySet
{
    /**
     * @param  array<string, string>  $embedded  kid => base64 key from the plugin config
     * @param  array<string, string>  $fetched  kid => base64url key from the cached JWKS
     */
    public function __construct(private readonly array $embedded, private readonly array $fetched = [])
    {
    }

    public function has(string $kid): bool
    {
        return $this->publicKey($kid) !== null;
    }

    public function publicKey(string $kid): ?string
    {
        $encoded = $this->embedded[$kid] ?? $this->fetched[$kid] ?? null;
        $key = $encoded === null ? null : Base64Url::decode($encoded);

        return $key !== null && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    /**
     * The usable Ed25519 entries of a /keys response.
     *
     * @param  array<string, mixed>  $jwks
     * @return array<string, string>
     */
    public static function parseJwks(array $jwks): array
    {
        $keys = [];

        foreach (is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $jwk) {
            if (is_array($jwk) && ($jwk['kty'] ?? null) === 'OKP' && ($jwk['crv'] ?? null) === 'Ed25519' && is_string($jwk['kid'] ?? null) && is_string($jwk['x'] ?? null)) {
                $keys[$jwk['kid']] = $jwk['x'];
            }
        }

        return $keys;
    }

    /**
     * The kid of a token, read without verifying anything.
     */
    public static function kidOf(string $token): ?string
    {
        $header = Base64Url::decode(explode('.', $token)[0]);
        $decoded = $header === null ? null : json_decode($header, true);

        return is_array($decoded) && is_string($decoded['kid'] ?? null) ? $decoded['kid'] : null;
    }
}
