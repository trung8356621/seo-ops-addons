<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Decide which Widgets to disable when a Script execution digest changes.
 *
 * @phpstan-type WidgetRow array{
 *     package_key: string,
 *     is_enabled?: bool,
 *     script_package_key?: string|null,
 *     script_version?: string|null,
 *     script_digest?: string|null
 * }
 */
final class WidgetInvalidationPolicy
{
    public const REASON = 'script_updated';

    /**
     * @param  list<WidgetRow>  $widgets
     * @return list<array{
     *     package_key: string,
     *     reason: string,
     *     meta: array{old_version: string|null, new_version: string, old_digest: string|null, new_digest: string, old_execution_digest: string|null, new_execution_digest: string}
     * }>
     */
    public function widgetsToDisable(
        string $scriptPackageKey,
        string $newVersion,
        string $newContentDigest,
        string $newExecutionDigest,
        ?string $oldVersion,
        ?string $oldContentDigest,
        ?string $oldExecutionDigest,
        array $widgets,
        bool $executionChanged,
    ): array {
        if (! $executionChanged) {
            return [];
        }

        $out = [];
        foreach ($widgets as $widget) {
            if (($widget['script_package_key'] ?? null) !== $scriptPackageKey) {
                continue;
            }
            if (array_key_exists('is_enabled', $widget) && $widget['is_enabled'] === false) {
                continue;
            }
            $out[] = [
                'package_key' => (string) $widget['package_key'],
                'reason' => self::REASON,
                'meta' => [
                    'old_version' => $oldVersion,
                    'new_version' => $newVersion,
                    'old_digest' => $oldContentDigest,
                    'new_digest' => $newContentDigest,
                    'old_execution_digest' => $oldExecutionDigest,
                    'new_execution_digest' => $newExecutionDigest,
                ],
            ];
        }

        return $out;
    }
}
