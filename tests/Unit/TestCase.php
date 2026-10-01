<?php

namespace Veltrom\License\Tests\Unit;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Veltrom\License\Client;
use Veltrom\License\State\Installation;
use Veltrom\License\Testing\FakeTransport;
use Veltrom\License\Testing\FakeWordpress;
use Veltrom\License\Testing\TokenFactory;

abstract class TestCase extends BaseTestCase
{
    protected const KEY = 'SMPL-AAAA-BBBB-CCCC-DDDD';

    protected FakeWordpress $wp;

    protected FakeTransport $transport;

    protected TokenFactory $tokens;

    protected Client $client;

    protected function setUp(): void
    {
        $this->wp = new FakeWordpress();
        $this->transport = new FakeTransport();
        $this->tokens = new TokenFactory('https://api.veltrom.com', 'sample-plugin-pro');
        $this->client = $this->makeClient();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeClient(array $overrides = []): Client
    {
        return new Client($overrides + [
            'product' => 'sample-plugin-pro',
            'name' => 'Sample Plugin PRO',
            'version' => '1.0.0',
            'plugin_file' => '/var/www/wp-content/plugins/sample-plugin-pro/sample-plugin-pro.php',
            'public_keys' => $this->tokens->publicKeys(),
            'text_domain' => 'sample-plugin-pro',
            'support_email' => 'hello@veltrom.com',
        ], $this->wp, $this->transport);
    }

    protected function installationId(): string
    {
        return hash('sha256', Installation::normaliseUrl($this->wp->url));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function token(array $claims = []): string
    {
        return $this->tokens->token($this->installationId(), $this->wp->now(), $claims);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function activated(array $claims = []): void
    {
        $this->transport->respond('/licenses/activate', 201, $this->tokens->licenseResponse($this->token($claims)));
        self::assertTrue($this->client->activate(self::KEY)->ok);
    }

    protected function option(string $name): mixed
    {
        return $this->wp->options['veltrom_license_sample_plugin_pro_' . $name] ?? null;
    }
}
