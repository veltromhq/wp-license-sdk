<?php

namespace Veltrom\License\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Veltrom\License\Token\Base64Url;
use Veltrom\License\Token\KeySet;
use Veltrom\License\Token\TokenVerifier;

/**
 * A token and JWKS issued by the real veltrom.com app (TokenIssuer and
 * SigningKeyRepository), recorded with tests/Fixtures/README.md. If the server
 * changes its token format, this fails here before any site does.
 */
final class ServerContractTest extends TestCase
{
    public function testATokenIssuedByTheServerVerifiesWithItsPublishedKeys(): void
    {
        /** @var array{issuer: string, audience: string, installation_id: string, now: int, token: string, jwks: array<string, mixed>} $fixture */
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/server-token.json'), true);
        $keys = new KeySet([], KeySet::parseJwks($fixture['jwks']));

        $verification = (new TokenVerifier($fixture['issuer'], $fixture['audience']))
            ->verify($fixture['token'], fn (string $kid) => $keys->publicKey($kid), $fixture['installation_id'], $fixture['now'], 0);

        self::assertTrue($verification->isValid(), $verification->outcome);
        self::assertIsBool($verification->entitlements()['updates'] ?? null);
        self::assertSame(KeySet::kidOf($fixture['token']), array_key_first(KeySet::parseJwks($fixture['jwks'])));
        self::assertNotNull(Base64Url::decode($fixture['jwks']['keys'][0]['x']));
    }
}
