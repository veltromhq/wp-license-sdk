<?php

namespace Veltrom\License\Token;

/**
 * The client verification sequence of entitlement-token.md, steps 1 to 7;
 * step 8 (applying `ent`) is the caller's. Pure: no network, no storage.
 */
final class TokenVerifier
{
    /** entitlement-token.md step 7: how far behind the highest `srv` the clock may be. */
    public const CLOCK_TOLERANCE_SECONDS = 86400;

    public function __construct(private readonly string $issuer, private readonly string $audience)
    {
    }

    /**
     * @param  callable(string): ?string  $publicKeyFor  kid => raw 32-byte key, or null when unknown
     */
    public function verify(string $token, callable $publicKeyFor, string $installationId, int $now, int $highestServerTime): Verification
    {
        // 1. Parse.
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return Verification::failed(Verification::MALFORMED);
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $header = $this->json($headerB64);
        $claims = $this->json($payloadB64);
        $signature = Base64Url::decode($signatureB64);

        if ($header === null || $claims === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ($header['alg'] ?? null) !== 'EdDSA') {
            return Verification::failed(Verification::MALFORMED);
        }

        // 2. Key by kid.
        $kid = $header['kid'] ?? null;
        $publicKey = is_string($kid) ? $publicKeyFor($kid) : null;

        if ($publicKey === null || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return Verification::failed(Verification::UNKNOWN_KEY, $claims);
        }

        // 3. Signature over header.payload.
        if (! sodium_crypto_sign_verify_detached($signature, $headerB64 . '.' . $payloadB64, $publicKey)) {
            return Verification::failed(Verification::BAD_SIGNATURE);
        }

        // 4. Issuer and audience: a token for another product is rejected.
        if (($claims['iss'] ?? null) !== $this->issuer || ($claims['aud'] ?? null) !== $this->audience) {
            return Verification::failed(Verification::WRONG_AUDIENCE, $claims);
        }

        // 5. nbf <= now <= exp.
        if (! is_int($claims['nbf'] ?? null) || ! is_int($claims['exp'] ?? null)) {
            return Verification::failed(Verification::MALFORMED);
        }

        if ($now < $claims['nbf']) {
            return Verification::failed(Verification::NOT_YET_VALID, $claims);
        }

        if ($now > $claims['exp']) {
            return Verification::failed(Verification::EXPIRED, $claims);
        }

        // 6. Bound to this installation; a copied token is discarded.
        if (($claims['act']['installation_id'] ?? null) !== $installationId) {
            return Verification::failed(Verification::OTHER_INSTALLATION, $claims);
        }

        // 7. A clock far behind the newest server time seen forces an online check.
        $serverTime = is_int($claims['srv'] ?? null) ? $claims['srv'] : 0;

        if ($now < max($highestServerTime, $serverTime) - self::CLOCK_TOLERANCE_SECONDS) {
            return Verification::failed(Verification::CLOCK_BEHIND, $claims);
        }

        return Verification::valid($claims);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $segment): ?array
    {
        $decoded = Base64Url::decode($segment);
        $value = $decoded === null ? null : json_decode($decoded, true);

        return is_array($value) ? $value : null;
    }
}
