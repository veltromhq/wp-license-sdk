<?php

namespace Veltrom\License\State;

use Veltrom\License\Config;
use Veltrom\License\Wordpress\Wordpress;

/**
 * The identity this site sends (sdk-wordpress.md "Installation identity").
 * Nothing beyond this list is collected.
 */
final class Installation
{
    public function __construct(private readonly Config $config, private readonly Wordpress $wp)
    {
    }

    public function id(): string
    {
        return hash('sha256', self::normaliseUrl($this->wp->siteIdentityUrl()));
    }

    /**
     * @return array{type: string, id: string, name: string, metadata: array<string, mixed>}
     */
    public function payload(): array
    {
        $host = parse_url($this->wp->siteIdentityUrl(), PHP_URL_HOST);

        return [
            'type' => 'wordpress_site',
            'id' => $this->id(),
            'name' => is_string($host) ? $host : $this->wp->siteIdentityUrl(),
            'metadata' => $this->metadata(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'site_url' => $this->wp->siteUrl(),
            'home_url' => $this->wp->homeUrl(),
            'wp_version' => $this->wp->wordpressVersion(),
            'php_version' => PHP_VERSION,
            'multisite' => $this->wp->isMultisite(),
            'plugin_version' => $this->config->version,
        ];
    }

    /**
     * Lowercase, no scheme, no www., no port, no trailing slash:
     * https://WWW.Example.com:443/ and http://example.com are one site.
     */
    public static function normaliseUrl(string $url): string
    {
        $value = strtolower(trim($url));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = (string) preg_replace('#^www\.#', '', $value);

        $slash = strpos($value, '/');
        $host = $slash === false ? $value : substr($value, 0, $slash);
        $path = $slash === false ? '' : substr($value, $slash);
        $host = (string) preg_replace('#:\d+$#', '', $host);

        return rtrim($host . $path, '/');
    }
}
