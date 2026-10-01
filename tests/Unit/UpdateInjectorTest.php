<?php

namespace Veltrom\License\Tests\Unit;

use stdClass;

final class UpdateInjectorTest extends TestCase
{
    private const BASENAME = 'sample-plugin-pro/sample-plugin-pro.php';

    /**
     * @param  array<string, mixed>|null  $download
     */
    private function release(string $version, ?array $download): void
    {
        $this->transport->respond('/updates/check', 200, array_filter([
            'update_available' => true,
            'release' => ['version' => $version, 'published_at' => '2026-10-01T10:00:00Z', 'release_notes_url' => 'https://veltrom.com/changelog', 'requirements' => ['php' => '>=8.1', 'wp' => '>=6.4']],
            'download' => $download,
            'reason' => $download === null ? 'updates_not_allowed' : null,
        ], fn ($value) => $value !== null) + ['download' => $download]);
    }

    private function updateTransient(): stdClass
    {
        $transient = new stdClass();
        $transient->response = [];
        $transient->no_update = [];

        $result = $this->wp->applyFilters('pre_set_site_transient_update_plugins', $transient);
        self::assertInstanceOf(stdClass::class, $result);

        return $result;
    }

    public function testAnEntitledSiteGetsANativeUpdateWithAPackage(): void
    {
        $this->activated();
        $this->client->boot();
        $this->release('1.1.0', ['url' => 'https://api.veltrom.com/v1/updates/download/dl_X', 'expires_at' => '2026-10-03T10:00:00Z']);

        $entry = $this->updateTransient()->response[self::BASENAME];

        self::assertSame('1.1.0', $entry->new_version);
        self::assertSame('https://api.veltrom.com/v1/updates/download/dl_X', $entry->package);
        self::assertSame('sample-plugin-pro', $entry->slug);
        self::assertSame('8.1', $entry->requires_php);
        self::assertNull($this->client->updates()->renewalNotice());
    }

    public function testWithoutUpdatesTheVersionShowsWithoutAPackageAndWithARenewalPrompt(): void
    {
        $this->activated(['ent' => ['pro' => true, 'updates' => false]]);
        $this->client->boot();
        $this->release('1.1.0', null);

        $entry = $this->updateTransient()->response[self::BASENAME];

        self::assertSame('1.1.0', $entry->new_version);
        self::assertSame('', $entry->package);
        self::assertSame(['version' => '1.1.0', 'url' => 'https://veltrom.com/account'], $this->client->updates()->renewalNotice());

        ob_start();
        $this->wp->doAction('admin_notices');
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Sample Plugin PRO 1.1.0 is available', $html);
        self::assertStringContainsString('href="https://veltrom.com/account"', $html);
    }

    public function testTheServerIsAskedAtMostOnceInSixHours(): void
    {
        $this->activated();
        $this->client->boot();
        $this->release('1.1.0', ['url' => 'https://api.veltrom.com/v1/updates/download/dl_X']);

        $this->updateTransient();
        $this->updateTransient();
        $this->wp->travel(5 * 3600);
        $this->updateTransient();
        self::assertCount(1, $this->transport->requestsTo('/updates/check'));

        $this->wp->travel(2 * 3600);
        $this->updateTransient();
        self::assertCount(2, $this->transport->requestsTo('/updates/check'));
    }

    public function testAnUnreachableServerLeavesThePluginAsItIsAndIsNotAskedAgainForAnHour(): void
    {
        $this->activated();
        $this->client->boot();
        $this->transport->offline();

        $transient = $this->updateTransient();
        $this->updateTransient();

        self::assertArrayNotHasKey(self::BASENAME, $transient->response);
        self::assertSame('1.0.0', $transient->no_update[self::BASENAME]->new_version);
        self::assertCount(1, $this->transport->requestsTo('/updates/check'));
    }

    public function testWithoutALicenceTheServerIsNotAsked(): void
    {
        $this->client->boot();

        $this->updateTransient();

        self::assertSame([], $this->transport->requests);
    }

    public function testTheDetailsModalAnswersOnlyForThisPlugin(): void
    {
        $this->activated();
        $this->client->boot();
        $this->release('1.1.0', ['url' => 'https://api.veltrom.com/v1/updates/download/dl_X']);
        $this->updateTransient();

        $info = $this->wp->applyFilters('plugins_api', false, 'plugin_information', (object) ['slug' => 'sample-plugin-pro']);
        $other = $this->wp->applyFilters('plugins_api', false, 'plugin_information', (object) ['slug' => 'akismet']);

        self::assertIsObject($info);
        self::assertSame('Sample Plugin PRO', $info->name);
        self::assertSame('1.1.0', $info->version);
        self::assertStringContainsString('https://veltrom.com/changelog', $info->sections['changelog']);
        self::assertFalse($other);
    }

    public function testAFinishedUpdateClearsTheCachedCheck(): void
    {
        $this->activated();
        $this->client->boot();
        $this->release('1.1.0', ['url' => 'https://api.veltrom.com/v1/updates/download/dl_X']);
        $this->updateTransient();

        $this->wp->doAction('upgrader_process_complete', null, ['type' => 'plugin', 'plugins' => [self::BASENAME]]);
        $this->updateTransient();

        self::assertCount(2, $this->transport->requestsTo('/updates/check'));
    }
}
