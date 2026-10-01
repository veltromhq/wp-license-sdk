# veltrom/wp-license-sdk

Licensing for Veltrom's WordPress PRO plugins: activation, entitlements verified offline from a
signed token, a daily heartbeat, and native WordPress updates from the licensing server. Every
PRO plugin uses this package; no plugin implements licensing itself.

Requires PHP 8.1+, WordPress 6.4+ and the `sodium` extension (in PHP core). No runtime Composer
dependencies. Licence: GPL-2.0-or-later.

The contract it implements lives in the veltrom.com repository: `docs/licensing/sdk-wordpress.md`,
`docs/licensing/entitlement-token.md`, `docs/licensing/api-v1.md`.

## Integration

```php
use Veltrom\License\Client;

$licence = new Client([
    'product'      => 'product-revisions-for-woocommerce-pro', // the product slug on the licensing server
    'name'         => 'Product Revisions for WooCommerce — Pro',
    'version'      => WCPR_PRO_VERSION,
    'plugin_file'  => WCPR_PRO_FILE,
    'public_keys'  => require __DIR__ . '/keys.php',           // ['k202610' => 'base64 Ed25519 public key']
    'text_domain'  => 'product-revisions-for-woocommerce-pro',
    'purchase_url' => 'https://www.productrevisions.com/pricing',
    'support_email'=> 'hello@veltrom.com',
    'menu'         => ['parent' => 'woocommerce', 'title' => 'Licence', 'page_title' => 'Product Revisions Pro licence'],
]);

$licence->boot();

if ($licence->can('pro')) {
    // PRO feature
}
```

`boot()` registers the licence screen, the update hooks and the heartbeat. `can()` never touches
the network. Optional keys: `api_base` (default `https://api.veltrom.com/v1`), `issuer` (default
`https://api.veltrom.com`), `account_url` (default `https://veltrom.com/account`, where renewals
happen).

Call `$licence->uninstall()` from the plugin's `uninstall.php`; it removes every option,
transient and scheduled event the SDK created.

## Behaviour worth knowing

- **Offline is not unlicensed.** An unreachable server never changes what `can()` answers. The
  token lives for the plan's grace period (14 days by default); only after that does the screen
  ask the site to reconnect.
- **Every token is verified locally**: Ed25519 signature, issuer, product, validity window, the
  installation it was issued to, and a clock more than a day behind the newest server time seen.
- **Updates** are checked at most every six hours. A licence whose updates have ended still sees
  the new version, without a download, and a notice linking to the account to renew.
- **Errors** never reach the customer as codes. A 429 backs off for an hour; an activation
  removed on the server is replaced once, silently; an unrecognised key is marked, never deleted.
- **What is sent**: site URL, home URL, WordPress, PHP and plugin versions, and whether the site
  is a multisite. Nothing about the shop's content, users or traffic.
- **Multisite**: network-activated only; the licence lives at the network level.

## Scoping

Ship the SDK scoped under the plugin's own prefix so two plugins with different SDK versions can
coexist. `scoper.inc.php.dist` is the starting point; it keeps WordPress's own symbols
unprefixed.

## Testing a plugin

`Veltrom\License\Testing` has what a plugin's test suite needs to cover the four required states
(no licence, valid, updates expired, offline with a valid token) without a network:
`FakeWordpress`, `FakeTransport` and `TokenFactory`, which signs tokens with a throwaway key whose
public half goes into the client's `public_keys`.

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 8
composer lint      # Pint (PSR-12)
```

`tests/Fixtures/server-token.json` is a token issued by the real licensing server; the contract
test fails if the server's token format and this SDK drift apart.
