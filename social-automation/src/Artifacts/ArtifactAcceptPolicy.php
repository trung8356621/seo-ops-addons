<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Artifacts;

/**
 * Artifact upload gates — size, mime, forbidden secret metadata. Pure.
 */
final class ArtifactAcceptPolicy
{
    public const MAX_BYTES = 20_971_520; // 20 MiB

    /** @var list<string> */
    public const ALLOWED_MIME = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'video/mp4',
        'video/webm',
        'application/json',
        'text/plain',
    ];

    /** @var list<string> */
    private const FORBIDDEN_META_KEYS = [
        'cookie', 'cookies', 'password', 'token', 'secret', 'authorization', 'api_key',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(string $mime, int $sizeBytes, array $metadata = []): array
    {
        $errors = [];
        $mime = strtolower(trim($mime));
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            $errors[] = 'mime_not_allowed';
        }
        if ($sizeBytes < 0 || $sizeBytes > self::MAX_BYTES) {
            $errors[] = 'size_out_of_range';
        }
        foreach ($metadata as $key => $_value) {
            $k = strtolower((string) $key);
            if (in_array($k, self::FORBIDDEN_META_KEYS, true)) {
                $errors[] = 'metadata_forbidden:'.$k;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
