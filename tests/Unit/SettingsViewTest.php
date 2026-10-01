<?php

namespace Veltrom\License\Tests\Unit;

use Veltrom\License\Admin\SettingsView;
use Veltrom\License\Config;
use Veltrom\License\LicenseState;

final class SettingsViewTest extends TestCase
{
    private function view(): SettingsView
    {
        return SettingsView::for($this->client->state(), $this->client->config, $this->wp);
    }

    public function testWithoutALicenceTheScreenAsksForTheKey(): void
    {
        $view = SettingsView::for($this->client->state(), Config::fromArray([
            'product' => 'sample-plugin-pro', 'version' => '1.0.0', 'plugin_file' => '/p/sample-plugin-pro/sample-plugin-pro.php',
            'public_keys' => $this->tokens->publicKeys(), 'text_domain' => 'sample-plugin-pro', 'purchase_url' => 'https://veltrom.com/sample',
        ]), $this->wp);

        self::assertTrue($view->showKeyInput);
        self::assertFalse($view->showDeactivate);
        self::assertSame('https://veltrom.com/sample', $view->primaryLinkUrl);
    }

    public function testAnActiveLicenceShowsTheMaskedKeySeatsAndDates(): void
    {
        $this->activated();

        $view = $this->view();
        $values = array_column($view->details, 'value', 'label');

        self::assertSame('Licence active', $view->headline);
        self::assertSame('SMPL-••••-••••-••••-DDDD', $values['Licence key']);
        self::assertSame('1 of 1 in use', $values['Sites']);
        self::assertSame('1 October 2027', $values['Updates and support until']);
        self::assertFalse($view->showKeyInput);
        self::assertNull($view->quietNote);
    }

    public function testEndedUpdatesPromptARenewalInTheAccount(): void
    {
        $this->activated(['ent' => ['pro' => true, 'updates' => false]]);

        $view = $this->view();

        self::assertSame('warning', $view->tone);
        self::assertStringContainsString('Everything keeps working', (string) $view->explanation);
        self::assertSame('https://veltrom.com/account', $view->primaryLinkUrl);
    }

    public function testAFailedPaymentPointsToBilling(): void
    {
        $this->activated(['lic' => ['status' => 'grace']]);

        self::assertSame(LicenseState::GRACE, $this->client->state()->status);
        self::assertSame('Manage billing', $this->view()->primaryLinkLabel);
    }

    public function testARevokedLicenceExplainsAndNamesSupport(): void
    {
        $this->activated();
        $this->transport->error('/licenses/validate', 403, 'license_revoked');
        $this->client->validate(true);

        $view = $this->view();

        self::assertSame('error', $view->tone);
        self::assertStringContainsString('hello@veltrom.com', (string) $view->explanation);
    }

    public function testAQuietLineAfterDaysWithoutReachingTheServer(): void
    {
        $this->activated();
        $this->wp->travel(3 * 86400);

        self::assertSame('Last checked 3 days ago.', $this->view()->quietNote);
        self::assertSame('Licence active', $this->view()->headline);
    }

    public function testAnExpiredTokenAsksForAConnection(): void
    {
        $this->activated();
        $this->wp->travel(15 * 86400);

        $view = $this->view();

        self::assertSame('This site needs to reach the licensing server', $view->headline);
        self::assertTrue($view->showRefresh);
    }

    public function testNoRawErrorCodeEverReachesTheScreen(): void
    {
        $this->activated();
        $this->transport->error('/licenses/validate', 404, 'invalid_license');
        $this->client->validate(true);

        $view = $this->view();
        $text = implode(' ', [$view->headline, (string) $view->explanation]);

        self::assertStringNotContainsString('invalid_license', $text);
        self::assertTrue($view->showKeyInput);
    }
}
