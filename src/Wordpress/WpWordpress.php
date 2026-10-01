<?php

namespace Veltrom\License\Wordpress;

/**
 * The production adapter. Network-active plugins keep their licence at the
 * network level (sdk-wordpress.md "Multisite"), so options go to sitemeta.
 */
final class WpWordpress implements Wordpress
{
    public function __construct(private readonly bool $network = false)
    {
    }

    public function getOption(string $name): mixed
    {
        return $this->network ? get_site_option($name, null) : get_option($name, null);
    }

    public function updateOption(string $name, mixed $value): void
    {
        if ($this->network) {
            update_site_option($name, $value);

            return;
        }

        update_option($name, $value, false);
    }

    public function deleteOption(string $name): void
    {
        $this->network ? delete_site_option($name) : delete_option($name);
    }

    public function getTransient(string $name): mixed
    {
        return $this->network ? get_site_transient($name) : get_transient($name);
    }

    public function setTransient(string $name, mixed $value, int $ttlSeconds): void
    {
        $this->network ? set_site_transient($name, $value, $ttlSeconds) : set_transient($name, $value, $ttlSeconds);
    }

    public function deleteTransient(string $name): void
    {
        $this->network ? delete_site_transient($name) : delete_transient($name);
    }

    public function now(): int
    {
        return time();
    }

    public function randomInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public function scheduleSingleEvent(int $timestamp, string $hook): void
    {
        wp_schedule_single_event($timestamp, $hook);
    }

    public function nextScheduled(string $hook): int|false
    {
        return wp_next_scheduled($hook);
    }

    public function clearScheduledHook(string $hook): void
    {
        wp_clear_scheduled_hook($hook);
    }

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_action($hook, $callback, $priority, $acceptedArgs);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_filter($hook, $callback, $priority, $acceptedArgs);
    }

    public function siteIdentityUrl(): string
    {
        return $this->network ? network_home_url() : home_url();
    }

    public function homeUrl(): string
    {
        return home_url();
    }

    public function siteUrl(): string
    {
        return site_url();
    }

    public function wordpressVersion(): string
    {
        return (string) get_bloginfo('version');
    }

    public function isMultisite(): bool
    {
        return is_multisite();
    }

    public function isNetworkActive(string $pluginFile): bool
    {
        if (! is_multisite()) {
            return false;
        }

        if (! function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename($pluginFile));
    }

    public function pluginBasename(string $pluginFile): string
    {
        return plugin_basename($pluginFile);
    }

    public function adminUrl(string $path): string
    {
        return $this->network ? network_admin_url($path) : admin_url($path);
    }

    public function currentUserCan(string $capability): bool
    {
        return current_user_can($capability);
    }

    public function translate(string $text, string $domain): string
    {
        // phpcs:ignore WordPress.WP.I18n -- the plugin's own text domain, passed in by config.
        return translate($text, $domain);
    }
}
