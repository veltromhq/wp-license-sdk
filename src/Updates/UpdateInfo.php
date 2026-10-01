<?php

namespace Veltrom\License\Updates;

/**
 * A cached answer from /updates/check, in the three shapes of api-v1.md
 * plus "the check failed", which is cached briefly so a down server is not
 * asked on every update check.
 */
final class UpdateInfo
{
    public const NONE = 'none';
    public const AVAILABLE = 'available';
    public const NOT_ENTITLED = 'not_entitled';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $version = null,
        public readonly ?string $package = null,
        public readonly ?string $requiresPhp = null,
        public readonly ?string $requiresWp = null,
        public readonly ?string $releaseNotesUrl = null,
        public readonly ?string $publishedAt = null,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        if (($data['update_available'] ?? false) !== true || ! is_array($data['release'] ?? null)) {
            return self::none();
        }

        $release = $data['release'];
        $download = is_array($data['download'] ?? null) ? $data['download'] : null;
        $requirements = is_array($release['requirements'] ?? null) ? $release['requirements'] : [];

        return new self(
            status: $download !== null && is_string($download['url'] ?? null) ? self::AVAILABLE : self::NOT_ENTITLED,
            version: is_string($release['version'] ?? null) ? $release['version'] : null,
            package: $download !== null && is_string($download['url'] ?? null) ? $download['url'] : null,
            requiresPhp: self::minimum($requirements['php'] ?? null),
            requiresWp: self::minimum($requirements['wp'] ?? null),
            releaseNotesUrl: is_string($release['release_notes_url'] ?? null) ? $release['release_notes_url'] : null,
            publishedAt: is_string($release['published_at'] ?? null) ? $release['published_at'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;

        return new self((string) ($data['status'] ?? self::NONE), $string('version'), $string('package'), $string('requires_php'), $string('requires_wp'), $string('release_notes_url'), $string('published_at'));
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'version' => $this->version,
            'package' => $this->package,
            'requires_php' => $this->requiresPhp,
            'requires_wp' => $this->requiresWp,
            'release_notes_url' => $this->releaseNotesUrl,
            'published_at' => $this->publishedAt,
        ];
    }

    public function isNewerThan(string $installed): bool
    {
        return in_array($this->status, [self::AVAILABLE, self::NOT_ENTITLED], true)
            && $this->version !== null
            && version_compare(ltrim($this->version, 'vV'), ltrim($installed, 'vV'), '>');
    }

    /**
     * ">=8.1" → "8.1": WordPress compares the bare minimum version.
     */
    private static function minimum(mixed $constraint): ?string
    {
        if (! is_string($constraint) || preg_match('/(\d+(?:\.\d+){0,2})/', $constraint, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
