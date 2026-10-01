<?php

namespace Veltrom\License;

use Veltrom\License\Admin\SettingsPage;
use Veltrom\License\Api\ApiClient;
use Veltrom\License\Api\ApiResponse;
use Veltrom\License\Http\Transport;
use Veltrom\License\Http\TransportException;
use Veltrom\License\Http\WpHttpTransport;
use Veltrom\License\State\Installation;
use Veltrom\License\State\LicenseStore;
use Veltrom\License\Token\KeySet;
use Veltrom\License\Token\TokenVerifier;
use Veltrom\License\Token\Verification;
use Veltrom\License\Updates\UpdateInjector;
use Veltrom\License\Wordpress\Wordpress;
use Veltrom\License\Wordpress\WpWordpress;

/**
 * The SDK's public surface (sdk-wordpress.md "Public surface"). Everything
 * else is internal.
 *
 * Error handling (sdk-wordpress.md rules 1-5): an unreachable server is never
 * a licensing failure; a 429 backs off; activation_not_found re-activates once,
 * silently; invalid_license marks the key without deleting it; ambiguity
 * fails open.
 */
final class Client
{
    /** How long a locally verified decision is trusted before re-verifying (sdk-wordpress.md "Storage"). */
    public const DECISION_TTL = 3600;

    /** How long the SDK stays quiet after a 429. */
    public const BACKOFF_TTL = 3600;

    /** A validate() that is not forced is skipped within this window of the last one. */
    public const MIN_VALIDATE_INTERVAL = 3600;

    public readonly Config $config;

    private readonly Wordpress $wp;

    private readonly LicenseStore $store;

    private readonly ApiClient $api;

    private readonly Installation $installation;

    private readonly TokenVerifier $verifier;

    private readonly Messages $messages;

    private readonly Heartbeat $heartbeat;

    private readonly UpdateInjector $updates;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config, ?Wordpress $wordpress = null, ?Transport $transport = null)
    {
        $this->config = Config::fromArray($config);
        $this->wp = $wordpress ?? new WpWordpress((new WpWordpress())->isNetworkActive($this->config->pluginFile));
        $this->store = new LicenseStore($this->config, $this->wp);
        $this->api = new ApiClient($this->config, $transport ?? new WpHttpTransport());
        $this->installation = new Installation($this->config, $this->wp);
        $this->verifier = new TokenVerifier($this->config->issuer, $this->config->product);
        $this->messages = new Messages($this->config, $this->wp);
        $this->heartbeat = new Heartbeat($this->config, $this->wp, fn () => $this->validate(true));
        $this->updates = new UpdateInjector($this->config, $this->wp, $this->store, $this->api);
    }

    /**
     * The whole required integration: licence screen, update hooks and the
     * daily heartbeat.
     */
    public function boot(): void
    {
        $this->heartbeat->register();
        $this->enableUpdates();
        (new SettingsPage($this->config, $this->wp, $this))->register();
    }

    public function enableUpdates(): void
    {
        $this->updates->register();
    }

    /**
     * For the plugin's own "updates" UI and tests; the hooks call it themselves.
     */
    public function updates(): UpdateInjector
    {
        return $this->updates;
    }

    public function activate(string $licenseKey): Result
    {
        $licenseKey = trim($licenseKey);

        if ($licenseKey === '') {
            return Result::error('empty_key', $this->messages->for('empty_key'));
        }

        if ($this->isBackingOff()) {
            return $this->failure('rate_limit_exceeded');
        }

        try {
            $response = $this->api->activate($licenseKey, $this->installation->payload());
        } catch (TransportException) {
            return $this->failure('network');
        }

        if (! $response->ok) {
            return $this->rejected($response);
        }

        $this->store->set('key', $licenseKey);
        $this->store->forget('last_error');

        return $this->accept($response) ? Result::ok() : $this->failure('invalid_token');
    }

    public function deactivate(): Result
    {
        $key = $this->store->string('key');
        $activationId = $this->store->string('activation_id');

        if ($key !== null && $activationId !== null) {
            try {
                $response = $this->api->deactivate($key, $activationId);
            } catch (TransportException) {
                return $this->failure('network');
            }

            // A licence the server no longer knows is gone either way.
            if (! $response->ok && ! in_array($response->errorCode, ['activation_not_found', 'invalid_license', 'license_revoked'], true)) {
                return $this->rejected($response);
            }
        }

        $this->store->forget('key', 'activation_id', 'token', 'license', 'last_checked', 'last_error');
        $this->store->forgetCached('decision', 'update', 'notice');

        return Result::ok();
    }

    public function validate(bool $force = false): Result
    {
        $key = $this->store->string('key');
        $activationId = $this->store->string('activation_id');

        if ($key === null || $activationId === null) {
            return $this->failure('no_license');
        }

        $lastChecked = $this->store->get('last_checked');

        if (! $force && is_int($lastChecked) && $this->wp->now() - $lastChecked < self::MIN_VALIDATE_INTERVAL) {
            return Result::ok();
        }

        if ($this->isBackingOff()) {
            return $this->failure('rate_limit_exceeded');
        }

        try {
            $response = $this->api->validate($key, $activationId, $this->installation->metadata());
        } catch (TransportException) {
            // Rule 1: remembered for the screen, but the cached token stands.
            $this->store->set('last_error', 'network');

            return $this->failure('network');
        }

        if ($response->ok) {
            if ($this->accept($response)) {
                return Result::ok();
            }

            // A valid answer bound to another identity: the site moved. Start
            // a fresh activation once rather than discarding the licence.
            return $this->reactivate($key);
        }

        return match ($response->errorCode) {
            // Rule 3: removed server-side; one silent fresh activation.
            'activation_not_found' => $this->reactivate($key),
            default => $this->rejected($response),
        };
    }

    /**
     * Whether an entitlement is granted. Never makes a network request and
     * never blocks a page load (sdk-wordpress.md "Integration").
     */
    public function can(string $entitlement): bool
    {
        $cached = $this->store->cached('decision');

        if (is_array($cached)) {
            return ($cached[$entitlement] ?? false) === true;
        }

        $token = $this->store->string('token');

        if ($token === null) {
            return false;
        }

        $verification = $this->verifyLocally($token);

        if ($verification->isValid()) {
            $entitlements = $verification->entitlements();
            $exp = (int) $verification->claims['exp'];

            $this->store->cache('decision', $entitlements, max(1, min(self::DECISION_TTL, $exp - $this->wp->now())));
            $this->store->set('last_decision', $entitlements);

            return ($entitlements[$entitlement] ?? false) === true;
        }

        return match ($verification->outcome) {
            // entitlement-token.md step 2: an unknown kid keeps the previous
            // decision until the heartbeat has fetched /keys.
            Verification::UNKNOWN_KEY => $this->previousDecision($entitlement),
            Verification::CLOCK_BEHIND, Verification::OTHER_INSTALLATION => $this->checkSoon(),
            default => false,
        };
    }

    public function state(): LicenseState
    {
        $key = $this->store->string('key');

        if ($key === null) {
            return new LicenseState(LicenseState::NONE, null, null, null, null, null, null, false, []);
        }

        $license = $this->store->get('license');
        $license = is_array($license) ? $license : [];
        $lastError = $this->store->string('last_error');
        $lastChecked = $this->store->get('last_checked');
        $token = $this->store->string('token');
        $verification = $token === null ? null : $this->verifyLocally($token);
        $entitlements = $verification?->isValid() ? $verification->entitlements() : [];

        $status = match (true) {
            $lastError === 'license_revoked' => LicenseState::REVOKED,
            $lastError === 'license_suspended' => LicenseState::SUSPENDED,
            $lastError === 'invalid_license' => LicenseState::INVALID_KEY,
            $verification === null || ! ($verification->isValid() || $verification->outcome === Verification::UNKNOWN_KEY) => LicenseState::NEEDS_CONNECTION,
            ($verification->claims['lic']['status'] ?? null) === 'grace' => LicenseState::GRACE,
            ($entitlements['updates'] ?? true) === false => LicenseState::UPDATES_EXPIRED,
            default => LicenseState::ACTIVE,
        };

        return new LicenseState(
            status: $status,
            maskedKey: self::mask($key),
            updatesUntil: is_string($license['updates_until'] ?? null) ? $license['updates_until'] : null,
            expiresAt: is_string($license['expires_at'] ?? null) ? $license['expires_at'] : null,
            activationLimit: is_int($license['activation_limit'] ?? null) ? $license['activation_limit'] : null,
            activationsUsed: is_int($license['activations_used'] ?? null) ? $license['activations_used'] : null,
            lastChecked: is_int($lastChecked) ? $lastChecked : null,
            lastCheckFailed: $lastError === 'network',
            entitlements: $entitlements,
        );
    }

    /**
     * Everything the SDK stored, for the plugin's uninstall routine.
     */
    public function uninstall(): void
    {
        $this->store->wipe();
        $this->heartbeat->unschedule();
    }

    /**
     * The key as the screen shows it: prefix and last four.
     */
    public static function mask(string $key): string
    {
        $parts = explode('-', strtoupper($key));

        if (count($parts) < 3) {
            return str_repeat('•', 4);
        }

        return $parts[0] . '-' . implode('-', array_fill(0, count($parts) - 2, '••••')) . '-' . end($parts);
    }

    /**
     * Stores the token and licence details from an activate or validate
     * answer, but only after verifying the token exactly as can() will.
     */
    private function accept(ApiResponse $response): bool
    {
        $token = $response->data['token'] ?? null;

        if (! is_string($token)) {
            return false;
        }

        $verification = $this->verifyLocally($token, refreshKeys: true);

        if (! $verification->isValid()) {
            return false;
        }

        $activation = $response->data['activation'] ?? [];
        $activationId = is_array($activation) && is_string($activation['id'] ?? null) ? $activation['id'] : $this->store->string('activation_id');

        $this->store->set('activation_id', $activationId);
        $this->store->set('token', $token);
        $this->store->set('license', is_array($response->data['license'] ?? null) ? $response->data['license'] : []);
        $this->store->set('last_checked', $this->wp->now());
        $this->store->set('highest_srv', max((int) $this->store->get('highest_srv'), (int) ($verification->claims['srv'] ?? 0)));
        $this->store->forget('last_error');
        $this->store->forgetCached('decision', 'update', 'notice');

        return true;
    }

    private function reactivate(string $key): Result
    {
        $this->store->forget('activation_id', 'token');
        $this->store->forgetCached('decision');

        return $this->activate($key);
    }

    private function rejected(ApiResponse $response): Result
    {
        $code = $response->errorCode ?? 'server_error';

        if ($response->isRateLimited()) {
            // Rule 2: back off; never retry inside the same request.
            $this->store->cache('backoff', 1, self::BACKOFF_TTL);
        } elseif (in_array($code, ['invalid_license', 'license_revoked', 'license_suspended', 'license_expired', 'product_mismatch'], true) && $this->store->string('key') !== null) {
            // Rule 4: the key is marked, never deleted; the token stays until it expires.
            $this->store->set('last_error', $code);
        }

        return $this->failure($code);
    }

    private function failure(string $code): Result
    {
        return Result::error($code, $this->messages->for($code));
    }

    private function isBackingOff(): bool
    {
        return $this->store->cached('backoff') !== false;
    }

    private function verifyLocally(string $token, bool $refreshKeys = false): Verification
    {
        $verification = $this->verifier->verify($token, fn (string $kid) => $this->keySet()->publicKey($kid), $this->installation->id(), $this->wp->now(), (int) $this->store->get('highest_srv'));

        if ($refreshKeys && $verification->outcome === Verification::UNKNOWN_KEY && $this->refreshKeys()) {
            $verification = $this->verifier->verify($token, fn (string $kid) => $this->keySet()->publicKey($kid), $this->installation->id(), $this->wp->now(), (int) $this->store->get('highest_srv'));
        }

        return $verification;
    }

    private function keySet(): KeySet
    {
        $fetched = $this->store->get('jwks');

        return new KeySet($this->config->publicKeys, is_array($fetched) ? $fetched : []);
    }

    private function refreshKeys(): bool
    {
        try {
            $response = $this->api->keys();
        } catch (TransportException) {
            return false;
        }

        if (! $response->ok) {
            return false;
        }

        $this->store->set('jwks', KeySet::parseJwks($response->data));

        return true;
    }

    private function previousDecision(string $entitlement): bool
    {
        $this->checkSoon();
        $previous = $this->store->get('last_decision');

        return is_array($previous) && ($previous[$entitlement] ?? false) === true;
    }

    private function checkSoon(): bool
    {
        $this->heartbeat->runSoon();

        return false;
    }
}
