<?php

namespace Veltrom\License\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Veltrom\License\State\Installation;

final class InstallationTest extends TestCase
{
    /**
     * @return iterable<array{string, string}>
     */
    public static function urls(): iterable
    {
        yield ['https://WWW.Example.com/', 'example.com'];
        yield ['http://example.com', 'example.com'];
        yield ['https://example.com:8443/shop/', 'example.com/shop'];
        yield ['example.com/', 'example.com'];
    }

    #[DataProvider('urls')]
    public function testUrlsAreNormalisedAsTheSpecSays(string $url, string $normalised): void
    {
        self::assertSame($normalised, Installation::normaliseUrl($url));
    }

    public function testTheActivationCarriesTheIdentityAndNothingMore(): void
    {
        $this->activated();

        $installation = $this->transport->requestsTo('/licenses/activate')[0]['json']['installation'] ?? [];

        self::assertSame('wordpress_site', $installation['type']);
        self::assertSame($this->installationId(), $installation['id']);
        self::assertSame('shop.example.com', $installation['name']);
        self::assertSame(['site_url', 'home_url', 'wp_version', 'php_version', 'multisite', 'plugin_version'], array_keys($installation['metadata']));
    }
}
