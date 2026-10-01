<?php

namespace Veltrom\License;

use Veltrom\License\Wordpress\Wordpress;

/**
 * error.code → a sentence in the plugin's text domain. A customer never sees
 * a raw code (sdk-wordpress.md "Admin screen").
 */
final class Messages
{
    public function __construct(private readonly Config $config, private readonly Wordpress $wp)
    {
    }

    public function for(string $code): string
    {
        $text = match ($code) {
            'invalid_license', 'empty_key' => 'That licence key was not recognised. Check it for typos; you can copy it from your account.',
            'product_mismatch' => 'That licence key belongs to a different product.',
            'license_expired' => 'This licence has expired.',
            'license_suspended' => 'This licence is suspended. Please contact support.',
            'license_revoked' => 'This licence has been revoked.',
            'activation_limit_reached' => 'This licence is already in use on as many sites as it covers. Deactivate one in your account, or upgrade.',
            'staging_not_allowed' => 'This licence cannot be activated on a staging or development site.',
            'rate_limit_exceeded' => 'Too many attempts. Please try again in an hour.',
            'network' => 'The licensing server could not be reached. Your licence keeps working; please try again later.',
            'invalid_token' => 'The licensing server sent an answer this plugin could not verify. Please contact support.',
            'no_license' => 'There is no licence on this site yet.',
            default => 'Something went wrong while talking to the licensing server. Please try again later.',
        };

        return $this->wp->translate($text, $this->config->textDomain);
    }
}
