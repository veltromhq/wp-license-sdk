<?php

namespace Veltrom\License;

use InvalidArgumentException;

/**
 * The integration array of sdk-wordpress.md, validated once (spec V55).
 */
final class Config
{
    /**
     * @param  array<string, string>  $publicKeys  kid => base64 Ed25519 public key
     */
    private function __construct(
        public readonly string $product,
        public readonly string $name,
        public readonly string $version,
        public readonly string $pluginFile,
        public readonly array $publicKeys,
        public readonly string $textDomain,
        public readonly string $apiBase,
        public readonly string $issuer,
        public readonly string $accountUrl,
        public readonly ?string $purchaseUrl,
        public readonly ?string $supportEmail,
        public readonly string $menuParent,
        public readonly string $menuTitle,
        public readonly string $pageTitle,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        foreach (['product', 'version', 'plugin_file', 'public_keys', 'text_domain'] as $required) {
            if (! isset($config[$required]) || $config[$required] === '' || $config[$required] === []) {
                throw new InvalidArgumentException("Veltrom licence config is missing '{$required}'.");
            }
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', (string) $config['product']) !== 1) {
            throw new InvalidArgumentException('Veltrom licence config: product must be the product slug.');
        }

        if (! is_array($config['public_keys'])) {
            throw new InvalidArgumentException('Veltrom licence config: public_keys must map kid to a base64 public key.');
        }

        $keys = [];
        foreach ($config['public_keys'] as $kid => $key) {
            $keys[(string) $kid] = (string) $key;
        }

        $product = (string) $config['product'];

        return new self(
            product: $product,
            name: (string) ($config['name'] ?? $product),
            version: (string) $config['version'],
            pluginFile: (string) $config['plugin_file'],
            publicKeys: $keys,
            textDomain: (string) $config['text_domain'],
            apiBase: rtrim((string) ($config['api_base'] ?? 'https://api.veltrom.com/v1'), '/'),
            issuer: (string) ($config['issuer'] ?? 'https://api.veltrom.com'),
            accountUrl: (string) ($config['account_url'] ?? 'https://veltrom.com/account'),
            purchaseUrl: isset($config['purchase_url']) ? (string) $config['purchase_url'] : null,
            supportEmail: isset($config['support_email']) ? (string) $config['support_email'] : null,
            menuParent: (string) ($config['menu']['parent'] ?? 'options-general.php'),
            menuTitle: (string) ($config['menu']['title'] ?? 'Licence'),
            pageTitle: (string) ($config['menu']['page_title'] ?? 'Licence'),
        );
    }

    /**
     * The plugin's directory name, which is what WordPress calls its slug.
     */
    public function slug(): string
    {
        return basename(dirname($this->pluginFile));
    }

    /**
     * Every option, transient and hook name the SDK owns starts with this
     * (spec V61), so two Veltrom plugins on one site never share state.
     */
    public function prefix(): string
    {
        return 'veltrom_license_' . str_replace('-', '_', $this->product) . '_';
    }
}
