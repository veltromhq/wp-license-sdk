<?php

namespace Veltrom\License\Wordpress;

/**
 * Every WordPress function the SDK touches, behind one seam. The licensing
 * logic is plain PHP tested against Testing\FakeWordpress; WpWordpress is the
 * thin production implementation.
 */
interface Wordpress
{
    public function getOption(string $name): mixed;

    /** Always stored with autoload off. */
    public function updateOption(string $name, mixed $value): void;

    public function deleteOption(string $name): void;

    /** @return mixed false when absent or expired, as WordPress returns it */
    public function getTransient(string $name): mixed;

    public function setTransient(string $name, mixed $value, int $ttlSeconds): void;

    public function deleteTransient(string $name): void;

    public function now(): int;

    public function randomInt(int $min, int $max): int;

    public function scheduleSingleEvent(int $timestamp, string $hook): void;

    public function nextScheduled(string $hook): int|false;

    public function clearScheduledHook(string $hook): void;

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;

    /** The URL that identifies this installation: the network's main site when network-active. */
    public function siteIdentityUrl(): string;

    public function homeUrl(): string;

    public function siteUrl(): string;

    public function wordpressVersion(): string;

    public function isMultisite(): bool;

    public function isNetworkActive(string $pluginFile): bool;

    public function pluginBasename(string $pluginFile): string;

    public function adminUrl(string $path): string;

    public function currentUserCan(string $capability): bool;

    public function translate(string $text, string $domain): string;
}
