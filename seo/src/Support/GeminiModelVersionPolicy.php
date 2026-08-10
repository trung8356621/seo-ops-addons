<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Rule version Gemini cho auto-routing: giữ record cũ trong DB/Model Status,
 * nhưng loại Gemini major < MIN_MAJOR khỏi runtime routing.
 */
final class GeminiModelVersionPolicy
{
    public const MIN_MAJOR_VERSION = 3;

    public const ROUTING_ENABLED = 'enabled';

    public const ROUTING_DISABLED = 'disabled';

    public const REASON_LEGACY_VERSION = 'legacy_version';

    public const REASON_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const REASON_DEPRECATED = 'deprecated';

    public const REASON_SHUTDOWN = 'shutdown';

    public const CAPABILITY_AUTO_ROUTING_KEY = 'auto_routing';

    public static function isGeminiFamily(string $modelSlug): bool
    {
        $slug = GoogleAiModelRegistry::normalizeSlug($modelSlug);

        return $slug !== '' && str_starts_with($slug, 'gemini-');
    }

    public static function majorVersion(string $modelSlug): ?int
    {
        $slug = GoogleAiModelRegistry::normalizeSlug($modelSlug);
        if ($slug === '' || ! self::isGeminiFamily($slug)) {
            return null;
        }

        if (preg_match('/^gemini-(\d+)(?:[.\-]|$)/', $slug, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function meetsMinimumMajorVersion(
        string $modelSlug,
        int $minimumMajor = self::MIN_MAJOR_VERSION,
    ): bool {
        if (! self::isGeminiFamily($modelSlug)) {
            // Imagen / Veo / Claude / unknown-non-gemini: không áp Gemini version gate.
            return true;
        }

        $major = self::majorVersion($modelSlug);

        return $major !== null && $major >= $minimumMajor;
    }

    public static function isPreview(string $modelSlug): bool
    {
        $slug = GoogleAiModelRegistry::normalizeSlug($modelSlug);

        return $slug !== '' && (
            str_contains($slug, 'preview')
            || str_contains($slug, '-exp')
            || str_ends_with($slug, '-experimental')
        );
    }

    public static function isDeprecatedOrShutdown(string $modelSlug, ?array $storedCapabilities = null): bool
    {
        $slug = GoogleAiModelRegistry::normalizeSlug($modelSlug);
        $capabilities = is_array($storedCapabilities) ? $storedCapabilities : [];
        $lifecycle = strtolower(trim((string) ($capabilities['lifecycle'] ?? '')));

        if (in_array($lifecycle, ['deprecated', 'shutdown', 'retired'], true)) {
            return true;
        }

        $auto = is_array($capabilities[self::CAPABILITY_AUTO_ROUTING_KEY] ?? null)
            ? $capabilities[self::CAPABILITY_AUTO_ROUTING_KEY]
            : [];
        $reason = strtolower(trim((string) ($auto['disabled_reason'] ?? $auto['reason'] ?? '')));

        return in_array($reason, [self::REASON_DEPRECATED, self::REASON_SHUTDOWN], true)
            || str_contains($slug, 'deprecated')
            || str_contains($slug, 'shutdown');
    }

    public static function isAutoRoutingDisabledByCapability(?array $storedCapabilities): bool
    {
        if (! is_array($storedCapabilities)) {
            return false;
        }

        $auto = is_array($storedCapabilities[self::CAPABILITY_AUTO_ROUTING_KEY] ?? null)
            ? $storedCapabilities[self::CAPABILITY_AUTO_ROUTING_KEY]
            : [];

        if (array_key_exists('enabled', $auto) && $auto['enabled'] === false) {
            return true;
        }

        return (bool) ($auto['auto_disabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>|null  $storedCapabilities
     * @return array{routing_status: string, disabled_reason: string|null}
     */
    public static function routingDecision(string $modelSlug, ?array $storedCapabilities = null): array
    {
        if (self::isAutoRoutingDisabledByCapability($storedCapabilities)) {
            $auto = is_array($storedCapabilities[self::CAPABILITY_AUTO_ROUTING_KEY] ?? null)
                ? $storedCapabilities[self::CAPABILITY_AUTO_ROUTING_KEY]
                : [];

            return [
                'routing_status' => self::ROUTING_DISABLED,
                'disabled_reason' => trim((string) ($auto['disabled_reason'] ?? $auto['reason'] ?? self::REASON_PROVIDER_UNAVAILABLE))
                    ?: self::REASON_PROVIDER_UNAVAILABLE,
            ];
        }

        if (self::isDeprecatedOrShutdown($modelSlug, $storedCapabilities)) {
            return [
                'routing_status' => self::ROUTING_DISABLED,
                'disabled_reason' => self::REASON_DEPRECATED,
            ];
        }

        if (self::isGeminiFamily($modelSlug) && ! self::meetsMinimumMajorVersion($modelSlug)) {
            return [
                'routing_status' => self::ROUTING_DISABLED,
                'disabled_reason' => self::REASON_LEGACY_VERSION,
            ];
        }

        return [
            'routing_status' => self::ROUTING_ENABLED,
            'disabled_reason' => null,
        ];
    }

    public static function isEligibleForAutoRouting(string $modelSlug, ?array $storedCapabilities = null): bool
    {
        return self::routingDecision($modelSlug, $storedCapabilities)['routing_status'] === self::ROUTING_ENABLED;
    }

    /**
     * @param  array<string, mixed>|null  $capabilities
     * @return array<string, mixed>
     */
    public static function markCapabilitiesUnavailable(?array $capabilities, string $message): array
    {
        $capabilities = is_array($capabilities) ? $capabilities : [];
        $capabilities[self::CAPABILITY_AUTO_ROUTING_KEY] = [
            'enabled' => false,
            'auto_disabled' => true,
            'disabled_reason' => self::REASON_PROVIDER_UNAVAILABLE,
            'message' => mb_substr($message, 0, 500),
            'marked_at' => now()->toIso8601String(),
        ];

        return $capabilities;
    }

    public static function isProviderUnavailableError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'no longer available')
            || str_contains($lower, 'not available')
            || str_contains($lower, 'unavailable')
            || str_contains($lower, 'has been shutdown')
            || str_contains($lower, 'model is deprecated')
            || (str_contains($lower, 'not found') && str_contains($lower, 'model'));
    }

    /**
     * Stable trước preview trong cùng danh sách đã lọc.
     *
     * @param  list<string>  $models
     * @return list<string>
     */
    public static function preferStableFirst(array $models): array
    {
        $stable = [];
        $preview = [];

        foreach ($models as $model) {
            $slug = GoogleAiModelRegistry::normalizeSlug((string) $model);
            if ($slug === '') {
                continue;
            }

            if (self::isPreview($slug)) {
                $preview[] = $slug;
            } else {
                $stable[] = $slug;
            }
        }

        return array_values(array_unique(array_merge($stable, $preview)));
    }

    /**
     * @param  list<string>  $models
     * @return list<string>
     */
    public static function filterEligibleForAutoRouting(array $models, array $capabilitiesBySlug = []): array
    {
        $filtered = [];

        foreach ($models as $model) {
            $slug = GoogleAiModelRegistry::normalizeSlug((string) $model);
            if ($slug === '') {
                continue;
            }

            $stored = $capabilitiesBySlug[$slug] ?? $capabilitiesBySlug[$model] ?? null;
            if (! self::isEligibleForAutoRouting($slug, is_array($stored) ? $stored : null)) {
                continue;
            }

            $filtered[] = $slug;
        }

        return array_values(array_unique($filtered));
    }
}
