<?php

namespace Veltrom\License\Updates;

use stdClass;
use Veltrom\License\Api\ApiClient;
use Veltrom\License\Client;
use Veltrom\License\Config;
use Veltrom\License\Http\TransportException;
use Veltrom\License\State\LicenseStore;
use Veltrom\License\Wordpress\Wordpress;

/**
 * Native WordPress updates from the licensing server (sdk-wordpress.md
 * "Update integration"). The API is asked at most once per six hours; the
 * update filter itself never waits on the network when the cache is warm.
 *
 * A licence without `updates` still sees the new version, without a
 * package, and a notice with a renewal link (releases-and-updates.md: the
 * release is never hidden).
 */
final class UpdateInjector
{
    public const CACHE_TTL = 21600;

    public const FAILURE_TTL = 3600;

    public function __construct(
        private readonly Config $config,
        private readonly Wordpress $wp,
        private readonly LicenseStore $store,
        private readonly ApiClient $api,
    ) {
    }

    public function register(): void
    {
        $this->wp->addFilter('pre_set_site_transient_update_plugins', fn (mixed $transient) => $this->inject($transient), 10, 1);
        $this->wp->addFilter('plugins_api', fn (mixed $result, mixed $action = null, mixed $args = null) => $this->pluginInformation($result, $action, $args), 10, 3);
        $this->wp->addAction('upgrader_process_complete', fn (mixed $upgrader = null, mixed $options = []) => $this->afterUpgrade($options), 10, 2);
        $this->wp->addAction('admin_notices', fn () => $this->printRenewalNotice(), 10, 0);
        $this->wp->addAction('network_admin_notices', fn () => $this->printRenewalNotice(), 10, 0);
    }

    public function inject(mixed $transient): mixed
    {
        // WordPress's update_plugins transient is a stdClass.
        if (! $transient instanceof stdClass) {
            return $transient;
        }

        $update = $this->check();
        $basename = $this->wp->pluginBasename($this->config->pluginFile);

        if ($update->isNewerThan($this->config->version)) {
            $transient->response ??= [];
            $transient->response[$basename] = $this->entry($update->version ?? $this->config->version, $update->package ?? '', $update);
            unset($transient->no_update[$basename]);
        } else {
            $transient->no_update ??= [];
            $transient->no_update[$basename] = $this->entry($this->config->version, '', $update);
        }

        return $transient;
    }

    public function pluginInformation(mixed $result, mixed $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || ! is_object($args) || ($args->slug ?? null) !== $this->config->slug()) {
            return $result;
        }

        $update = $this->check(network: false);

        $info = new stdClass();
        $info->name = $this->config->name;
        $info->slug = $this->config->slug();
        $info->version = $update->version ?? $this->config->version;
        $info->requires_php = $update->requiresPhp;
        $info->requires = $update->requiresWp;
        $info->last_updated = $update->publishedAt;
        $info->download_link = $update->package ?? '';
        $info->homepage = $this->config->accountUrl;
        $info->sections = [
            'changelog' => $update->releaseNotesUrl !== null
                ? '<p><a href="' . htmlspecialchars($update->releaseNotesUrl, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($this->wp->translate('Release notes', $this->config->textDomain), ENT_QUOTES) . '</a></p>'
                : '',
        ];

        return $info;
    }

    /**
     * @param  mixed  $options  WordPress's upgrader options array
     */
    public function afterUpgrade(mixed $options): void
    {
        $plugins = is_array($options) && ($options['type'] ?? null) === 'plugin' ? (array) ($options['plugins'] ?? []) : [];

        if (in_array($this->wp->pluginBasename($this->config->pluginFile), $plugins, true)) {
            $this->store->forgetCached('update');
        }
    }

    /**
     * The renewal prompt for a licence whose updates have ended, read from the
     * cache only: a notice on every admin page must not reach the network.
     *
     * @return array{version: string, url: string}|null
     */
    public function renewalNotice(): ?array
    {
        $update = $this->check(network: false);

        if ($update->status !== UpdateInfo::NOT_ENTITLED || ! $update->isNewerThan($this->config->version)) {
            return null;
        }

        return ['version' => (string) $update->version, 'url' => $this->config->accountUrl];
    }

    public function check(bool $network = true): UpdateInfo
    {
        $cached = $this->store->cached('update');

        if (is_array($cached)) {
            return UpdateInfo::fromArray($cached);
        }

        $key = $this->store->string('key');

        if (! $network || $key === null || $this->store->cached('backoff') !== false) {
            return UpdateInfo::none();
        }

        try {
            $response = $this->api->checkUpdate($key, $this->store->string('activation_id'), $this->config->version);
        } catch (TransportException) {
            $this->store->cache('update', UpdateInfo::failed()->toArray(), self::FAILURE_TTL);

            return UpdateInfo::failed();
        }

        if (! $response->ok) {
            if ($response->isRateLimited()) {
                $this->store->cache('backoff', 1, Client::BACKOFF_TTL);
            }

            $this->store->cache('update', UpdateInfo::failed()->toArray(), self::FAILURE_TTL);

            return UpdateInfo::failed();
        }

        $update = UpdateInfo::fromResponse($response->data);
        $this->store->cache('update', $update->toArray(), self::CACHE_TTL);

        return $update;
    }

    private function printRenewalNotice(): void
    {
        if (! $this->wp->currentUserCan('update_plugins')) {
            return;
        }

        $notice = $this->renewalNotice();

        if ($notice === null) {
            return;
        }

        $text = sprintf(
            $this->wp->translate('%1$s %2$s is available. Your licence no longer includes updates; renew to install it.', $this->config->textDomain),
            $this->config->name,
            $notice['version'],
        );

        echo '<div class="notice notice-warning"><p>' . htmlspecialchars($text, ENT_QUOTES)
            . ' <a href="' . htmlspecialchars($notice['url'], ENT_QUOTES) . '">'
            . htmlspecialchars($this->wp->translate('Renew updates', $this->config->textDomain), ENT_QUOTES)
            . '</a></p></div>';
    }

    private function entry(string $version, string $package, UpdateInfo $update): stdClass
    {
        $entry = new stdClass();
        $entry->id = $this->wp->pluginBasename($this->config->pluginFile);
        $entry->slug = $this->config->slug();
        $entry->plugin = $this->wp->pluginBasename($this->config->pluginFile);
        $entry->new_version = $version;
        $entry->url = $this->config->accountUrl;
        $entry->package = $package;
        $entry->requires_php = $update->requiresPhp;
        $entry->requires = $update->requiresWp;

        return $entry;
    }
}
