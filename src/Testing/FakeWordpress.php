<?php

namespace Veltrom\License\Testing;

use Veltrom\License\Wordpress\Wordpress;

/**
 * An in-memory WordPress for tests: options, expiring transients, a
 * controllable clock and cron, and hooks that tests can fire.
 */
final class FakeWordpress implements Wordpress
{
    /** @var array<string, mixed> */
    public array $options = [];

    /** @var array<string, array{value: mixed, expires: int}> */
    public array $transients = [];

    /** @var array<string, int> */
    public array $scheduled = [];

    /** @var array<string, list<callable>> */
    public array $hooks = [];

    public int $time = 1790000000;

    public ?int $nextRandom = null;

    public string $url = 'https://shop.example.com';

    public bool $multisite = false;

    public bool $networkActive = false;

    /** @var list<string> */
    public array $capabilities = ['manage_options', 'update_plugins'];

    public function getOption(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    public function updateOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    public function deleteOption(string $name): void
    {
        unset($this->options[$name]);
    }

    public function getTransient(string $name): mixed
    {
        $entry = $this->transients[$name] ?? null;

        if ($entry === null || $entry['expires'] <= $this->time) {
            return false;
        }

        return $entry['value'];
    }

    public function setTransient(string $name, mixed $value, int $ttlSeconds): void
    {
        $this->transients[$name] = ['value' => $value, 'expires' => $this->time + $ttlSeconds];
    }

    public function deleteTransient(string $name): void
    {
        unset($this->transients[$name]);
    }

    public function now(): int
    {
        return $this->time;
    }

    public function travel(int $seconds): void
    {
        $this->time += $seconds;
    }

    public function randomInt(int $min, int $max): int
    {
        return $this->nextRandom !== null ? max($min, min($max, $this->nextRandom)) : intdiv($min + $max, 2);
    }

    public function scheduleSingleEvent(int $timestamp, string $hook): void
    {
        $this->scheduled[$hook] = $timestamp;
    }

    public function nextScheduled(string $hook): int|false
    {
        return $this->scheduled[$hook] ?? false;
    }

    public function clearScheduledHook(string $hook): void
    {
        unset($this->scheduled[$hook]);
    }

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->hooks[$hook][] = $callback;
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->hooks[$hook][] = $callback;
    }

    /**
     * Runs a filter's callbacks over a value, the way apply_filters would.
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->hooks[$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        foreach ($this->hooks[$hook] ?? [] as $callback) {
            $callback(...$args);
        }
    }

    public function siteIdentityUrl(): string
    {
        return $this->url;
    }

    public function homeUrl(): string
    {
        return $this->url;
    }

    public function siteUrl(): string
    {
        return $this->url;
    }

    public function wordpressVersion(): string
    {
        return '6.8';
    }

    public function isMultisite(): bool
    {
        return $this->multisite;
    }

    public function isNetworkActive(string $pluginFile): bool
    {
        return $this->networkActive;
    }

    public function pluginBasename(string $pluginFile): string
    {
        return basename(dirname($pluginFile)) . '/' . basename($pluginFile);
    }

    public function adminUrl(string $path): string
    {
        return $this->url . '/wp-admin/' . ltrim($path, '/');
    }

    public function currentUserCan(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function translate(string $text, string $domain): string
    {
        return $text;
    }
}
