<?php

namespace Veltrom\License\Admin;

use Veltrom\License\Config;
use Veltrom\License\LicenseState;
use Veltrom\License\Wordpress\Wordpress;

/**
 * What the licence screen shows for each state of sdk-wordpress.md "Admin
 * screen". Plain data, so every state is unit-tested; the template only
 * escapes and prints it.
 */
final class SettingsView
{
    /**
     * @param  list<array{label: string, value: string}>  $details
     */
    public function __construct(
        public readonly string $tone,
        public readonly string $headline,
        public readonly ?string $explanation,
        public readonly array $details,
        public readonly bool $showKeyInput,
        public readonly bool $showDeactivate,
        public readonly bool $showRefresh,
        public readonly ?string $primaryLinkLabel,
        public readonly ?string $primaryLinkUrl,
        public readonly ?string $quietNote,
    ) {
    }

    public static function for(LicenseState $state, Config $config, Wordpress $wp): self
    {
        $t = fn (string $text): string => $wp->translate($text, $config->textDomain);
        $details = self::details($state, $t);
        $support = $config->supportEmail !== null ? sprintf($t('Write to %s and we will sort it out.'), $config->supportEmail) : $t('Please contact support.');
        $quiet = self::quietNote($state, $wp, $t);

        return match ($state->status) {
            LicenseState::NONE => new self('info', $t('Enter your licence key'), $t('You will find it in the email sent after your purchase and in your account.'), [], true, false, false, $config->purchaseUrl !== null ? $t('Buy a licence') : null, $config->purchaseUrl, null),
            LicenseState::ACTIVE => new self('success', $t('Licence active'), null, $details, false, true, true, null, null, $quiet),
            LicenseState::UPDATES_EXPIRED => new self('warning', $t('Updates have ended'), $t('Everything keeps working. Renew to receive new versions and support again; your key stays the same.'), $details, false, true, true, $t('Renew updates'), $config->accountUrl, $quiet),
            LicenseState::GRACE => new self('warning', $t('Your last payment did not go through'), $t('Everything keeps working while the payment is retried. Updating your card in your account fixes it.'), $details, false, true, true, $t('Manage billing'), $config->accountUrl, $quiet),
            LicenseState::SUSPENDED => new self('error', $t('This licence is suspended'), $support, $details, false, true, true, null, null, null),
            LicenseState::REVOKED => new self('error', $t('This licence has been revoked'), $support, $details, false, true, false, null, null, null),
            LicenseState::INVALID_KEY => new self('error', $t('This licence key was not recognised'), $t('Check the key for typos and enter it again. You can copy it from your account.'), $details, true, true, false, $t('Open your account'), $config->accountUrl, null),
            default => new self('error', $t('This site needs to reach the licensing server'), $t('The last licence check was too long ago. Make sure the site can connect to the internet, then check again.'), $details, false, true, true, null, null, $quiet),
        };
    }

    /**
     * @param  callable(string): string  $t
     * @return list<array{label: string, value: string}>
     */
    private static function details(LicenseState $state, callable $t): array
    {
        $details = [];

        if ($state->maskedKey !== null) {
            $details[] = ['label' => $t('Licence key'), 'value' => $state->maskedKey];
        }

        if ($state->updatesUntil !== null) {
            $details[] = ['label' => $t('Updates and support until'), 'value' => self::date($state->updatesUntil)];
        }

        if ($state->expiresAt !== null) {
            $details[] = ['label' => $t('Licence valid until'), 'value' => self::date($state->expiresAt)];
        }

        if ($state->activationLimit !== null && $state->activationsUsed !== null) {
            $details[] = ['label' => $t('Sites'), 'value' => sprintf($t('%1$d of %2$d in use'), $state->activationsUsed, $state->activationLimit)];
        }

        return $details;
    }

    /**
     * "Offline, token still valid → a quiet status line" (sdk-wordpress.md).
     *
     * @param  callable(string): string  $t
     */
    private static function quietNote(LicenseState $state, Wordpress $wp, callable $t): ?string
    {
        if ($state->lastChecked === null) {
            return null;
        }

        $days = intdiv(max(0, $wp->now() - $state->lastChecked), 86400);

        if ($days < 2 && ! $state->lastCheckFailed) {
            return null;
        }

        return $days < 1 ? $t('The last check could not reach the licensing server.') : sprintf($t('Last checked %d days ago.'), $days);
    }

    private static function date(string $iso): string
    {
        $timestamp = strtotime($iso);

        return $timestamp === false ? $iso : gmdate('j F Y', $timestamp);
    }
}
