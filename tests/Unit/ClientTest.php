<?php

namespace Veltrom\License\Tests\Unit;

use Veltrom\License\LicenseState;
use Veltrom\License\Testing\TokenFactory;

final class ClientTest extends TestCase
{
    public function testActivatingStoresTheLicenceAndGrantsItsEntitlements(): void
    {
        $this->activated(['ent' => ['pro' => true, 'updates' => true]]);

        self::assertTrue($this->client->can('pro'));
        self::assertFalse($this->client->can('cloud_sync'));
        self::assertSame(self::KEY, $this->option('key'));
        self::assertSame('01JBQ7XB5N8YZ2H4T6K9M1P3RS', $this->option('activation_id'));
        self::assertSame(LicenseState::ACTIVE, $this->client->state()->status);
        self::assertSame('SMPL-••••-••••-••••-DDDD', $this->client->state()->maskedKey);
    }

    public function testCanNeverTouchesTheNetwork(): void
    {
        $this->activated();
        $requests = count($this->transport->requests);
        $this->transport->offline();

        $this->wp->transients = [];
        self::assertTrue($this->client->can('pro'));
        self::assertTrue($this->client->can('pro'));
        self::assertCount($requests, $this->transport->requests);
    }

    public function testARejectedActivationStoresNothingAndSaysWhyInWords(): void
    {
        $this->transport->error('/licenses/activate', 403, 'activation_limit_reached');

        $result = $this->client->activate(self::KEY);

        self::assertFalse($result->ok);
        self::assertSame('activation_limit_reached', $result->code);
        self::assertStringContainsString('already in use on as many sites', $result->message);
        self::assertNull($this->option('key'));
        self::assertFalse($this->client->can('pro'));
    }

    public function testAnEmptyKeyIsRefusedWithoutAsking(): void
    {
        self::assertSame('empty_key', $this->client->activate('   ')->code);
        self::assertSame([], $this->transport->requests);
    }

    public function testATokenForAnotherProductIsNeverStored(): void
    {
        $this->transport->respond('/licenses/activate', 201, $this->tokens->licenseResponse($this->token(['aud' => 'other-plugin-pro'])));

        self::assertSame('invalid_token', $this->client->activate(self::KEY)->code);
        self::assertNull($this->option('token'));
    }

    public function testAnUnreachableServerIsNeverALicensingFailure(): void
    {
        $this->activated();
        $this->transport->offline();

        $result = $this->client->validate(true);

        self::assertSame('network', $result->code);
        self::assertTrue($this->client->can('pro'));
        self::assertSame(LicenseState::ACTIVE, $this->client->state()->status);
        self::assertTrue($this->client->state()->lastCheckFailed);
    }

    public function testATokenPastItsGraceTellsTheSiteToReconnect(): void
    {
        $this->activated();
        $this->wp->travel(15 * 86400);
        $this->wp->transients = [];

        self::assertFalse($this->client->can('pro'));
        self::assertSame(LicenseState::NEEDS_CONNECTION, $this->client->state()->status);
    }

    public function testValidatingRefreshesTheToken(): void
    {
        $this->activated(['ent' => ['pro' => true, 'updates' => true]]);
        $this->wp->travel(86400);
        $this->transport->respond('/licenses/validate', 200, $this->tokens->licenseResponse($this->token(['ent' => ['pro' => true, 'updates' => false]])));

        self::assertTrue($this->client->validate(true)->ok);
        self::assertFalse($this->client->can('updates'));
        self::assertSame(LicenseState::UPDATES_EXPIRED, $this->client->state()->status);
        self::assertSame($this->wp->now(), $this->option('last_checked'));
    }

    public function testAnUnforcedValidateWithinTheHourDoesNotCall(): void
    {
        $this->activated();

        self::assertTrue($this->client->validate()->ok);
        self::assertSame([], $this->transport->requestsTo('/licenses/validate'));
    }

    public function testATooManyRequestsAnswerBacksOffForAnHour(): void
    {
        $this->activated();
        $this->transport->error('/licenses/validate', 429, 'rate_limit_exceeded');

        self::assertSame('rate_limit_exceeded', $this->client->validate(true)->code);
        self::assertSame('rate_limit_exceeded', $this->client->validate(true)->code);
        self::assertCount(1, $this->transport->requestsTo('/licenses/validate'));

        $this->wp->travel(3601);
        $this->client->validate(true);
        self::assertCount(2, $this->transport->requestsTo('/licenses/validate'));
    }

    public function testAnActivationRemovedOnTheServerIsReplacedOnceSilently(): void
    {
        $this->activated();
        $this->transport->error('/licenses/validate', 404, 'activation_not_found');
        $this->transport->respond('/licenses/activate', 201, $this->tokens->licenseResponse($this->token()));

        self::assertTrue($this->client->validate(true)->ok);
        self::assertCount(2, $this->transport->requestsTo('/licenses/activate'));
        self::assertTrue($this->client->can('pro'));
    }

    public function testAnUnrecognisedKeyIsMarkedButNeverDeleted(): void
    {
        $this->activated();
        $this->transport->error('/licenses/validate', 404, 'invalid_license');

        $this->client->validate(true);

        self::assertSame(self::KEY, $this->option('key'));
        self::assertSame(LicenseState::INVALID_KEY, $this->client->state()->status);
    }

    public function testARevokedLicenceIsShownAsSuch(): void
    {
        $this->activated();
        $this->transport->error('/licenses/validate', 403, 'license_revoked');

        $this->client->validate(true);

        self::assertSame(LicenseState::REVOKED, $this->client->state()->status);
    }

    public function testATokenSignedWithARotatedKeyIsAcceptedAfterFetchingKeys(): void
    {
        $rotated = new TokenFactory('https://api.veltrom.com', 'sample-plugin-pro');
        $this->transport->respond('/licenses/activate', 201, $rotated->licenseResponse($rotated->token($this->installationId(), $this->wp->now(), [], 'k2027')));
        $this->transport->respond('/keys', 200, $rotated->jwks('k2027'));

        self::assertTrue($this->client->activate(self::KEY)->ok);
        self::assertTrue($this->client->can('pro'));
    }

    public function testAnUnknownKeyInCanKeepsThePreviousDecisionAndChecksSoon(): void
    {
        $this->activated();
        self::assertTrue($this->client->can('pro'));

        // A token whose key this site has not fetched yet (stored by an older run).
        $rotated = new TokenFactory('https://api.veltrom.com', 'sample-plugin-pro');
        $this->wp->options['veltrom_license_sample_plugin_pro_token'] = $rotated->token($this->installationId(), $this->wp->now(), [], 'k2027');
        $this->wp->transients = [];
        unset($this->wp->scheduled['veltrom_license_sample_plugin_pro_heartbeat']);

        self::assertTrue($this->client->can('pro'));
        self::assertSame($this->wp->now(), $this->wp->scheduled['veltrom_license_sample_plugin_pro_heartbeat'] ?? null);
    }

    public function testASiteThatMovedStartsAFreshActivation(): void
    {
        $this->activated();
        $this->wp->url = 'https://new-shop.example.com';
        $this->transport->respond('/licenses/validate', 200, $this->tokens->licenseResponse($this->tokens->token(hash('sha256', 'shop.example.com'), $this->wp->now())));
        $this->transport->respond('/licenses/activate', 201, $this->tokens->licenseResponse($this->token()));

        self::assertTrue($this->client->validate(true)->ok);
        self::assertSame('new-shop.example.com', $this->transport->requestsTo('/licenses/activate')[1]['json']['installation']['name'] ?? null);
        self::assertTrue($this->client->can('pro'));
    }

    public function testDeactivatingFreesTheSeatAndForgetsTheLicence(): void
    {
        $this->activated();
        $this->transport->respond('/licenses/deactivate', 200, ['success' => true]);

        self::assertTrue($this->client->deactivate()->ok);
        self::assertNull($this->option('key'));
        self::assertFalse($this->client->can('pro'));
        self::assertSame(LicenseState::NONE, $this->client->state()->status);
    }

    public function testDeactivatingWhileOfflineKeepsTheLicence(): void
    {
        $this->activated();
        $this->transport->offline();

        self::assertSame('network', $this->client->deactivate()->code);
        self::assertSame(self::KEY, $this->option('key'));
    }

    public function testUninstallLeavesNothingBehind(): void
    {
        $this->activated();
        $this->client->can('pro');
        $this->client->boot();

        $this->client->uninstall();

        $left = array_filter(array_keys($this->wp->options + $this->wp->transients + $this->wp->scheduled), fn (string $name) => str_starts_with($name, 'veltrom_license_sample_plugin_pro_'));
        self::assertSame([], array_values($left));
    }
}
