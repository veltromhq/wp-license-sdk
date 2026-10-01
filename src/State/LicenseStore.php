<?php

namespace Veltrom\License\State;

use Veltrom\License\Config;
use Veltrom\License\Wordpress\Wordpress;

/**
 * Everything the SDK keeps on the site (sdk-wordpress.md "Storage", spec
 * V61): options under the product's prefix with autoload off, and three
 * transients. Not encrypted on purpose: the site owner may read their own key.
 */
final class LicenseStore
{
    private const OPTIONS = ['key', 'activation_id', 'token', 'installation_id', 'highest_srv', 'license', 'last_checked', 'last_error', 'jwks', 'last_decision'];

    private const TRANSIENTS = ['decision', 'update', 'backoff', 'notice'];

    public function __construct(private readonly Config $config, private readonly Wordpress $wp)
    {
    }

    public function get(string $name): mixed
    {
        return $this->wp->getOption($this->config->prefix() . $name);
    }

    public function string(string $name): ?string
    {
        $value = $this->get($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $name, mixed $value): void
    {
        $this->wp->updateOption($this->config->prefix() . $name, $value);
    }

    public function forget(string ...$names): void
    {
        foreach ($names as $name) {
            $this->wp->deleteOption($this->config->prefix() . $name);
        }
    }

    public function cached(string $name): mixed
    {
        return $this->wp->getTransient($this->config->prefix() . $name);
    }

    public function cache(string $name, mixed $value, int $ttlSeconds): void
    {
        $this->wp->setTransient($this->config->prefix() . $name, $value, $ttlSeconds);
    }

    public function forgetCached(string ...$names): void
    {
        foreach ($names as $name) {
            $this->wp->deleteTransient($this->config->prefix() . $name);
        }
    }

    /**
     * Removes every trace of the licence from the site (the plugin's uninstall).
     */
    public function wipe(): void
    {
        $this->forget(...self::OPTIONS);
        $this->forgetCached(...self::TRANSIENTS);
    }
}
