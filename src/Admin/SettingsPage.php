<?php

namespace Veltrom\License\Admin;

use Veltrom\License\Client;
use Veltrom\License\Config;
use Veltrom\License\Wordpress\Wordpress;

/**
 * The licence screen, one per plugin, rendered by the SDK (sdk-wordpress.md
 * "Admin screen"). Form posts go to admin-post.php with a nonce and the
 * capability check; the outcome is shown once after the redirect.
 */
final class SettingsPage
{
    public function __construct(
        private readonly Config $config,
        private readonly Wordpress $wp,
        private readonly Client $client,
    ) {
    }

    public function register(): void
    {
        $network = $this->wp->isNetworkActive($this->config->pluginFile);

        $this->wp->addAction($network ? 'network_admin_menu' : 'admin_menu', fn () => $this->addPage($network), 10, 0);
        $this->wp->addAction('admin_post_' . $this->action(), fn () => $this->handle(), 10, 0);

        // Per-site activation on a network is not supported (spec V60).
        if ($this->wp->isMultisite() && ! $network) {
            $this->wp->addAction('admin_notices', fn () => $this->printNetworkNotice(), 10, 0);
        }
    }

    public function slug(): string
    {
        return $this->config->product . '-licence';
    }

    private function action(): string
    {
        return $this->config->prefix() . 'form';
    }

    private function capability(): string
    {
        return $this->wp->isNetworkActive($this->config->pluginFile) ? 'manage_network_options' : 'manage_options';
    }

    private function addPage(bool $network): void
    {
        add_submenu_page(
            $network ? 'settings.php' : $this->config->menuParent,
            $this->config->pageTitle,
            $this->config->menuTitle,
            $this->capability(),
            $this->slug(),
            fn () => $this->render(),
        );
    }

    private function handle(): void
    {
        if (! $this->wp->currentUserCan($this->capability())) {
            wp_die(esc_html($this->wp->translate('You are not allowed to change the licence.', $this->config->textDomain)), 403);
        }

        check_admin_referer($this->action());

        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';

        $result = match ($operation) {
            'activate' => $this->client->activate(isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : ''),
            'deactivate' => $this->client->deactivate(),
            'refresh' => $this->client->validate(true),
            default => null,
        };

        if ($result !== null) {
            $message = $result->ok
                ? $this->wp->translate($operation === 'deactivate' ? 'The licence was removed from this site.' : 'The licence is active on this site.', $this->config->textDomain)
                : $result->message;

            $this->wp->setTransient($this->config->prefix() . 'notice', ['ok' => $result->ok, 'message' => $message], 60);
        }

        wp_safe_redirect($this->wp->adminUrl(($this->wp->isNetworkActive($this->config->pluginFile) ? 'settings.php' : $this->config->menuParent) . '?page=' . $this->slug()));
        exit;
    }

    private function render(): void
    {
        $view = SettingsView::for($this->client->state(), $this->config, $this->wp);
        $notice = $this->wp->getTransient($this->config->prefix() . 'notice');
        $this->wp->deleteTransient($this->config->prefix() . 'notice');

        $config = $this->config;
        $action = $this->action();
        $postUrl = $this->wp->adminUrl('admin-post.php');
        $t = fn (string $text): string => $this->wp->translate($text, $this->config->textDomain);

        require dirname(__DIR__, 2) . '/templates/settings.php';
    }

    private function printNetworkNotice(): void
    {
        if (! $this->wp->currentUserCan('manage_network_plugins')) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf($this->wp->translate('%s must be network-activated to use its licence on a multisite network.', $this->config->textDomain), $this->config->name)),
        );
    }
}
