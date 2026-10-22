<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageDependency;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Read models for Filament Scripts / Widgets / Workflows pages.
 */
final class ScriptRegistryUiService
{
    public function __construct(
        private readonly ?ScriptReleaseMediaService $media = null,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listScriptsForUi(): array
    {
        if (! $this->ready()) {
            return [];
        }

        $media = $this->media ?? app(ScriptReleaseMediaService::class);
        $userLabels = [];
        $out = [];
        foreach (SaPackage::query()->where('kind', 'script')->orderBy('package_key')->get() as $package) {
            $latest = $this->latestRelease((int) $package->id);
            if ($latest === null) {
                continue;
            }
            $manifest = is_array($latest->manifest) ? $latest->manifest : [];
            $updatedById = $package->updated_by_user_id ?? $latest->created_by_user_id;
            $mediaStatus = $media->statusForRelease((int) $latest->id);
            $widgetDeps = $this->widgetsDependingOn((string) $package->package_key);
            $out[] = [
                'package_key' => (string) $package->package_key,
                'script_id' => (string) ($manifest['script_id'] ?? $package->package_key),
                'name' => (string) $package->name,
                'version' => (string) $latest->version,
                'release_id' => (int) $latest->id,
                'source' => (string) ($latest->sync_source ?? $package->sync_source ?? 'unknown'),
                'created_by_user_id' => $package->created_by_user_id,
                'updated_by_user_id' => $updatedById,
                'updated_by_label' => $this->userLabel($updatedById, $userLabels),
                'content_digest' => (string) $latest->content_digest,
                'execution_digest' => $latest->execution_digest
                    ? (string) $latest->execution_digest
                    : ScriptExecutionDigest::fromManifest($manifest),
                'published_at' => $latest->published_at?->toIso8601String(),
                'last_sync' => $latest->published_at?->toIso8601String(),
                'last_sync_label' => $latest->published_at?->timezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i') ?? '—',
                'status' => (string) $latest->status,
                'widget_dependencies' => $widgetDeps,
                'widget_dependency_count' => count($widgetDeps),
                'assets_complete' => $this->assetsComplete(is_array($manifest['assets'] ?? null) ? $manifest['assets'] : []),
                'has_video_demo' => ($mediaStatus['has_video'] ?? false) || is_array($manifest['video_demo'] ?? null),
                'video_demo_status' => (string) ($mediaStatus['video_demo_status'] ?? 'missing'),
                'has_playable_video' => (bool) ($mediaStatus['has_video'] ?? false),
                'has_thumbnail' => (bool) ($mediaStatus['has_thumbnail'] ?? false),
                'video_media_id' => $mediaStatus['video_media_id'] ?? null,
                'thumbnail_media_id' => $mediaStatus['thumbnail_media_id'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function scriptDetail(string $packageKey): ?array
    {
        if (! $this->ready()) {
            return null;
        }
        $package = SaPackage::query()->where('package_key', $packageKey)->where('kind', 'script')->first();
        if (! $package instanceof SaPackage) {
            return null;
        }
        $media = $this->media ?? app(ScriptReleaseMediaService::class);
        $userLabels = [];
        $releases = SaPackageRelease::query()
            ->where('package_id', $package->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (SaPackageRelease $r) use ($media): array {
                $manifest = is_array($r->manifest) ? $r->manifest : [];
                $mediaStatus = $media->statusForRelease((int) $r->id);

                return [
                    'id' => (int) $r->id,
                    'version' => (string) $r->version,
                    'content_digest' => (string) $r->content_digest,
                    'execution_digest' => $r->execution_digest,
                    'status' => (string) $r->status,
                    'published_at' => $r->published_at?->toIso8601String(),
                    'sync_source' => $r->sync_source,
                    'created_by_user_id' => $r->created_by_user_id,
                    'variables' => $manifest['variables'] ?? [],
                    'steps' => $manifest['steps'] ?? [],
                    'assets' => $manifest['assets'] ?? [],
                    'video_demo' => $manifest['video_demo'] ?? null,
                    'video_demo_status' => (string) ($mediaStatus['video_demo_status'] ?? 'missing'),
                    'video_media_id' => $mediaStatus['video_media_id'] ?? null,
                    'thumbnail_media_id' => $mediaStatus['thumbnail_media_id'] ?? null,
                    'runtime_requirements' => $manifest['runtime_requirements'] ?? [],
                ];
            })
            ->all();

        return [
            'package_key' => (string) $package->package_key,
            'name' => (string) $package->name,
            'description' => $package->description,
            'sync_source' => $package->sync_source,
            'created_by_user_id' => $package->created_by_user_id,
            'updated_by_user_id' => $package->updated_by_user_id,
            'updated_by_label' => $this->userLabel($package->updated_by_user_id, $userLabels),
            'widget_dependencies' => $this->widgetsDependingOn((string) $package->package_key),
            'releases' => $releases,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWidgetsForUi(): array
    {
        if (! $this->ready()) {
            return [];
        }
        $out = [];
        foreach (SaPackage::query()->where('kind', 'widget')->orderBy('package_key')->get() as $package) {
            $latest = $this->latestRelease((int) $package->id);
            $manifest = $latest && is_array($latest->manifest) ? $latest->manifest : [];
            $out[] = [
                'package_key' => (string) $package->package_key,
                'name' => (string) $package->name,
                'version' => $latest?->version,
                'is_enabled' => (bool) ($package->is_enabled ?? true),
                'disabled_reason' => $package->disabled_reason,
                'disabled_meta' => is_array($package->disabled_meta) ? $package->disabled_meta : [],
                'script_key' => $manifest['script_key'] ?? null,
                'script_version' => $manifest['script_version'] ?? null,
                'script_digest' => $manifest['script_digest'] ?? null,
                'form_fields' => $manifest['form_fields'] ?? [],
                'content_digest' => $latest?->content_digest,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWorkflowsForUi(): array
    {
        if (! $this->ready()) {
            return [];
        }
        $out = [];
        foreach (SaPackage::query()->where('kind', 'workflow')->orderBy('package_key')->get() as $package) {
            $latest = $this->latestRelease((int) $package->id);
            $manifest = $latest && is_array($latest->manifest) ? $latest->manifest : [];
            $steps = is_array($manifest['steps'] ?? null) ? $manifest['steps'] : [];
            $disabledSteps = [];
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $widgetKey = trim((string) ($step['widget_key'] ?? $step['package_key'] ?? ''));
                if ($widgetKey === '') {
                    continue;
                }
                $widget = SaPackage::query()->where('package_key', $widgetKey)->where('kind', 'widget')->first();
                if ($widget instanceof SaPackage && ! ($widget->is_enabled ?? true)) {
                    $disabledSteps[] = [
                        'widget_key' => $widgetKey,
                        'disabled_reason' => $widget->disabled_reason,
                    ];
                }
            }
            $out[] = [
                'package_key' => (string) $package->package_key,
                'name' => (string) $package->name,
                'version' => $latest?->version,
                'is_enabled' => (bool) ($package->is_enabled ?? true),
                'steps' => $steps,
                'disabled_steps' => $disabledSteps,
                'dispatchable' => $disabledSteps === [] && (bool) ($package->is_enabled ?? true),
                'content_digest' => $latest?->content_digest,
            ];
        }

        return $out;
    }

    public function setPackageEnabled(string $packageKey, bool $enabled): array
    {
        if (! $this->ready()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }
        $package = SaPackage::query()->where('package_key', $packageKey)->first();
        if (! $package instanceof SaPackage) {
            return ['ok' => false, 'errors' => ['package_not_found']];
        }
        if ($enabled) {
            $package->fill([
                'is_enabled' => true,
                'disabled_reason' => null,
                'disabled_meta' => null,
            ])->save();
        } else {
            $package->fill([
                'is_enabled' => false,
                'disabled_reason' => $package->disabled_reason ?: 'manual',
            ])->save();
        }

        return ['ok' => true, 'package_key' => $packageKey, 'is_enabled' => $enabled];
    }

    /**
     * @return list<array{package_key: string, name: string, is_enabled: bool, disabled_reason: string|null}>
     */
    private function widgetsDependingOn(string $scriptPackageKey): array
    {
        $out = [];
        foreach (SaPackage::query()->where('kind', 'widget')->get() as $widget) {
            $latest = $this->latestRelease((int) $widget->id);
            if ($latest === null) {
                continue;
            }
            $manifest = is_array($latest->manifest) ? $latest->manifest : [];
            $scriptKey = trim((string) ($manifest['script_key'] ?? $manifest['script_package_key'] ?? ''));
            if ($scriptKey !== $scriptPackageKey) {
                // Also check dependency rows.
                $dep = SaPackageDependency::query()
                    ->where('release_id', $latest->id)
                    ->where('dependency_package_key', $scriptPackageKey)
                    ->exists();
                if (! $dep) {
                    continue;
                }
            }
            $out[] = [
                'package_key' => (string) $widget->package_key,
                'name' => (string) $widget->name,
                'is_enabled' => (bool) ($widget->is_enabled ?? true),
                'disabled_reason' => $widget->disabled_reason !== null ? (string) $widget->disabled_reason : null,
            ];
        }

        return $out;
    }

    private function latestRelease(int $packageId): ?SaPackageRelease
    {
        $release = SaPackageRelease::query()
            ->where('package_id', $packageId)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        return $release instanceof SaPackageRelease ? $release : null;
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     */
    private function assetsComplete(array $assets): bool
    {
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                return false;
            }
            if (trim((string) ($asset['path'] ?? '')) === ''
                || ! PackageDigest::isValidHex(strtolower((string) ($asset['sha256'] ?? '')))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $cache
     */
    private function userLabel(int|string|null $userId, array &$cache): string
    {
        if ($userId === null || $userId === '' || (int) $userId <= 0) {
            return '—';
        }
        $id = (int) $userId;
        if (isset($cache[$id])) {
            return $cache[$id];
        }
        try {
            $user = User::query()->find($id);
            if ($user instanceof User) {
                $name = trim((string) ($user->name ?? ''));
                $email = trim((string) ($user->email ?? ''));
                $cache[$id] = $name !== '' ? ($email !== '' ? $name.' <'.$email.'>' : $name)
                    : ($email !== '' ? $email : '#'.$id);

                return $cache[$id];
            }
        } catch (Throwable) {
            // core users table may be unavailable in isolated unit contexts
        }
        $cache[$id] = '#'.$id;

        return $cache[$id];
    }

    private function ready(): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_packages');
        } catch (Throwable) {
            return false;
        }
    }
}
