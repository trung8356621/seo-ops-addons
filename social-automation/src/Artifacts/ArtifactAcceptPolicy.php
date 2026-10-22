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

    /**
     * Same job + checksum → treat as duplicate submission (idempotent accept).
     */
    public function isDuplicateChecksum(?string $existingChecksum, string $incomingChecksum): bool
    {
        $existing = strtolower(trim((string) $existingChecksum));
        $incoming = strtolower(trim($incomingChecksum));
        if ($existing === '' || $incoming === '' || ! preg_match('/^[a-f0-9]{64}$/', $incoming)) {
            return false;
        }

        return hash_equals($existing, $incoming);
    }

    /**
     * Success → final screenshot; failure → step where error occurred.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{ok: bool, errors: list<string>, phase: ?string}
     */
    public function validateScreenshotMetadata(string $outcome, array $metadata = []): array
    {
        $errors = [];
        foreach ($metadata as $key => $_value) {
            $k = strtolower((string) $key);
            if (in_array($k, self::FORBIDDEN_META_KEYS, true)) {
                $errors[] = 'metadata_forbidden:'.$k;
            }
        }

        $outcome = strtolower(trim($outcome));
        $phase = strtolower(trim((string) ($metadata['phase'] ?? $metadata['step'] ?? '')));
        if ($outcome === 'success' && $phase !== '' && ! in_array($phase, ['final', 'result'], true)) {
            $errors[] = 'screenshot_phase_invalid';
        }
        if ($outcome === 'failed' && $phase === '') {
            $errors[] = 'failure_step_required';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'phase' => $phase !== '' ? $phase : null];
        }

        return [
            'ok' => true,
            'errors' => [],
            'phase' => $phase !== '' ? $phase : ($outcome === 'success' ? 'final' : null),
        ];
    }
}
