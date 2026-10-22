<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Digest of executable Script content only.
 * Metadata / video_demo changes must not invalidate Widgets.
 */
final class ScriptExecutionDigest
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function fromManifest(array $manifest): string
    {
        $payload = [
            'script_id' => $manifest['script_id'] ?? null,
            'variables' => $manifest['variables'] ?? [],
            'outputs' => $manifest['outputs'] ?? [],
            'steps' => $manifest['steps'] ?? [],
            'runtime_requirements' => $manifest['runtime_requirements'] ?? [],
            'assets' => $manifest['assets'] ?? [],
        ];

        return PackageDigest::sha256OfCanonicalJson($payload);
    }

    /**
     * @param  array<string, mixed>  $previousManifest
     * @param  array<string, mixed>  $nextManifest
     */
    public static function executionChanged(array $previousManifest, array $nextManifest): bool
    {
        return ! PackageDigest::equals(
            self::fromManifest($previousManifest),
            self::fromManifest($nextManifest),
        );
    }
}
