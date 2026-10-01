<?php

namespace Veltrom\License\Tests\Unit;

use Veltrom\License\Heartbeat;

final class HeartbeatTest extends TestCase
{
    private const HOOK = 'veltrom_license_sample_plugin_pro_heartbeat';

    public function testBootSchedulesOneCheckADayAwayWithinSixHoursOfJitter(): void
    {
        $this->wp->nextRandom = -Heartbeat::JITTER;
        $this->client->boot();
        self::assertSame($this->wp->now() + 86400 - 21600, $this->wp->scheduled[self::HOOK]);

        unset($this->wp->scheduled[self::HOOK]);
        $this->wp->nextRandom = Heartbeat::JITTER;
        $this->makeClient()->boot();
        self::assertSame($this->wp->now() + 86400 + 21600, $this->wp->scheduled[self::HOOK]);
    }

    public function testBootDoesNotPushBackACheckAlreadyScheduled(): void
    {
        $this->wp->scheduled[self::HOOK] = $this->wp->now() + 600;

        $this->client->boot();

        self::assertSame($this->wp->now() + 600, $this->wp->scheduled[self::HOOK]);
    }

    public function testARunValidatesAndReschedulesWithFreshJitter(): void
    {
        $this->activated();
        $this->client->boot();
        $this->wp->travel(2 * 86400);
        $this->transport->respond('/licenses/validate', 200, $this->tokens->licenseResponse($this->token()));
        $this->wp->nextRandom = 1234;

        $this->wp->doAction(self::HOOK);

        self::assertCount(1, $this->transport->requestsTo('/licenses/validate'));
        self::assertSame($this->wp->now() + 86400 + 1234, $this->wp->scheduled[self::HOOK]);
    }

    public function testARunThatCannotReachTheServerStillReschedules(): void
    {
        $this->activated();
        $this->client->boot();
        $this->transport->offline();

        $this->wp->doAction(self::HOOK);

        self::assertArrayHasKey(self::HOOK, $this->wp->scheduled);
        self::assertTrue($this->client->can('pro'));
    }
}
