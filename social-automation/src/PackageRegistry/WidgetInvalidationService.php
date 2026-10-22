<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Disable Widgets pinned to a Script when executable content changes.
 * Does not delete Widgets/Workflows or mutate historical jobs.
 */
final class WidgetInvalidationService
{
    public function __construct(
        private readonly WidgetInvalidationPolicy $policy = new WidgetInvalidationPolicy,
    ) {}

    /**
     * @return list<array{package_key: string, reason: string, meta: array<string, mixed>}>
     */
    public function invalidateForScriptPublish(
        string $scriptPackageKey,
        string $newVersion,
        string $newContentDigest,
        string $newExecutionDigest,
        ?string $previousVersion,
        ?string $previousContentDigest,
        ?string $previousExecutionDigest,
        bool $executionChanged,
    ): array {
        if (! $executionChanged || ! $this->tablesReady()) {
            return [];
        }

        $widgets = $this->widgetsReferencing($scriptPackageKey);
        $actions = $this->policy->widgetsToDisable(
            $scriptPackageKey,
            $newVersion,
            $newContentDigest,
            $newExecutionDigest,
            $previousVersion,
            $previousContentDigest,
            $previousExecutionDigest,
            $widgets,
            true,
        );

        foreach ($actions as $action) {
            SaPackage::query()
                ->where('package_key', $action['package_key'])
                ->where('kind', 'widget')
                ->update([
                    'is_enabled' => false,
                    'disabled_reason' => $action['reason'],
                    'disabled_meta' => $action['meta'],
                ]);
        }

        return $actions;
    }

    /**
     * @return list<array{package_key: string, is_enabled: bool, script_package_key: string|null, script_version: string|null, script_digest: string|null}>
     */
    private function widgetsReferencing(string $scriptPackageKey): array
    {
        $out = [];
        $widgets = SaPackage::query()->where('kind', 'widget')->get();
        foreach ($widgets as $widget) {
            $latest = SaPackageRelease::query()
                ->where('package_id', $widget->id)
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->first();
            if (! $latest instanceof SaPackageRelease) {
                continue;
            }
            $manifest = is_array($latest->manifest) ? $latest->manifest : [];
            $scriptKey = trim((string) ($manifest['script_key'] ?? $manifest['script_package_key'] ?? ''));
            if ($scriptKey !== $scriptPackageKey) {
                continue;
            }
            $out[] = [
                'package_key' => (string) $widget->package_key,
                'is_enabled' => (bool) ($widget->is_enabled ?? true),
                'script_package_key' => $scriptKey,
                'script_version' => isset($manifest['script_version']) ? (string) $manifest['script_version'] : null,
                'script_digest' => isset($manifest['script_digest']) ? (string) $manifest['script_digest'] : null,
            ];
        }

        return $out;
    }

    private function tablesReady(): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_packages');
        } catch (Throwable) {
            return false;
        }
    }
}
