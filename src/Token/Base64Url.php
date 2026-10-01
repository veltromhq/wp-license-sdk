<?php

namespace Veltrom\License\Token;

final class Base64Url
{
    /**
     * Accepts base64url and plain base64, padded or not; null when the input
     * is not base64 at all.
     */
    public static function decode(string $value): ?string
    {
        $normalised = strtr($value, '-_', '+/');
        $padding = strlen($normalised) % 4;

        if ($padding > 0) {
            $normalised .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalised, true);

        return $decoded === false ? null : $decoded;
    }
}
