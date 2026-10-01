<?php

namespace Veltrom\License\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Veltrom\License\Testing\TokenFactory;
use Veltrom\License\Token\Base64Url;
use Veltrom\License\Token\TokenVerifier;
use Veltrom\License\Token\Verification;

final class TokenVerifierTest extends TestCase
{
    private const NOW = 1790000000;

    private const INSTALLATION = 'install-1';

    private TokenFactory $tokens;

    private TokenVerifier $verifier;

    protected function setUp(): void
    {
        $this->tokens = new TokenFactory('https://api.veltrom.com', 'sample-plugin-pro');
        $this->verifier = new TokenVerifier('https://api.veltrom.com', 'sample-plugin-pro');
    }

    private function verify(string $token, int $now = self::NOW, int $highestSrv = 0): Verification
    {
        $keys = $this->tokens->publicKeys();

        return $this->verifier->verify($token, fn (string $kid) => isset($keys[$kid]) ? Base64Url::decode($keys[$kid]) : null, self::INSTALLATION, $now, $highestSrv);
    }

    public function testAValidTokenYieldsItsEntitlements(): void
    {
        $verification = $this->verify($this->tokens->token(self::INSTALLATION, self::NOW, ['ent' => ['pro' => true, 'updates' => false]]));

        self::assertTrue($verification->isValid());
        self::assertSame(['pro' => true, 'updates' => false], $verification->entitlements());
    }

    /**
     * @return iterable<string, array{0: callable(TokenFactory): string, 1: string, 2?: int}>
     */
    public static function rejections(): iterable
    {
        yield 'not three segments' => [fn () => 'abc.def', Verification::MALFORMED];
        yield 'unknown kid' => [fn (TokenFactory $f) => $f->token(self::INSTALLATION, self::NOW, [], 'k-other'), Verification::UNKNOWN_KEY];
        yield 'tampered payload' => [function (TokenFactory $f) {
            [$h, , $s] = explode('.', $f->token(self::INSTALLATION, self::NOW));
            $forged = rtrim(strtr(base64_encode((string) json_encode(['ent' => ['pro' => true]])), '+/', '-_'), '=');

            return "$h.$forged.$s";
        }, Verification::BAD_SIGNATURE];
        yield 'another product' => [fn (TokenFactory $f) => $f->token(self::INSTALLATION, self::NOW, ['aud' => 'other-plugin-pro']), Verification::WRONG_AUDIENCE];
        yield 'another issuer' => [fn (TokenFactory $f) => $f->token(self::INSTALLATION, self::NOW, ['iss' => 'https://evil.example']), Verification::WRONG_AUDIENCE];
        yield 'not yet valid' => [fn (TokenFactory $f) => $f->token(self::INSTALLATION, self::NOW, ['nbf' => self::NOW + 60]), Verification::NOT_YET_VALID];
        yield 'expired' => [fn (TokenFactory $f) => $f->token(self::INSTALLATION, self::NOW, ['exp' => self::NOW - 1]), Verification::EXPIRED];
        yield 'copied from another site' => [fn (TokenFactory $f) => $f->token('install-2', self::NOW), Verification::OTHER_INSTALLATION];
        yield 'clock moved back a week' => [fn (TokenFactory $f) => $f->token(self::INSTALLATION, self::NOW - 8 * 86400, ['exp' => self::NOW + 86400, 'srv' => self::NOW]), Verification::CLOCK_BEHIND, self::NOW - 2 * 86400];
    }

    /**
     * @param  callable(TokenFactory): string  $make
     */
    #[DataProvider('rejections')]
    public function testEachStepOfTheSequenceRejects(callable $make, string $outcome, int $now = self::NOW): void
    {
        self::assertSame($outcome, $this->verify($make($this->tokens), $now)->outcome);
    }

    public function testAClockBehindTheHighestServerTimeEverSeenForcesAnOnlineCheck(): void
    {
        $token = $this->tokens->token(self::INSTALLATION, self::NOW - 10 * 86400);

        self::assertTrue($this->verify($token, self::NOW - 9 * 86400)->isValid());
        self::assertSame(Verification::CLOCK_BEHIND, $this->verify($token, self::NOW - 9 * 86400, highestSrv: self::NOW)->outcome);
    }

    public function testAClockWithinADayOfTheServerIsTrusted(): void
    {
        $token = $this->tokens->token(self::INSTALLATION, self::NOW, ['nbf' => self::NOW - 3600]);

        self::assertTrue($this->verify($token, self::NOW - 3600)->isValid());
    }
}
