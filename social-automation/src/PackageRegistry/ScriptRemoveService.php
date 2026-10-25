<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageDependency;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaTask;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Remove Script from SEO-OPS only — tombstone suppresses bidirectional re-import.
 */
final class ScriptRemoveService
{
    public function __construct(
        private readonly ScriptRemovePolicy $policy = new ScriptRemovePolicy,
        private readonly ScriptTombstoneService $tombstones = new ScriptTombstoneService,
        private readonly ScriptReleaseMediaService $media = new ScriptReleaseMediaService,
        private readonly ScriptRegistryUiService $ui = new ScriptRegistryUiService,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     outcome: string,
     *     errors: list<string>,
     *     dependencies?: list<array<string, mixed>>,
     *     history_refs?: list<array{ref_type: string, ref_id: string}>,
     *     script_id?: string|null,
     *     package_key?: string
     * }
     */
    public function remove(string $packageKey, ?int $userId = null, ?string $source = null): array
    {
        $packageKey = trim($packageKey);
        $source = trim($source ?? ScriptTombstoneService::SOURCE_RPA_ADMIN);
        if ($packageKey === '') {
            return ['ok' => false, 'outcome' => 'validation_failed', 'errors' => ['package_key_required']];
        }
        $readiness = $this->readiness();
        if (! $readiness['ok']) {
            Log::error('Social Automation package registry is unavailable for Script removal.', [
                'connection' => SocialAutomationServiceConfig::CONNECTION,
                'database' => config('database.connections.'.SocialAutomationServiceConfig::CONNECTION.'.database'),
                'missing_tables' => $readiness['missing_tables'],
                'exception' => $readiness['exception'],
            ]);

            return [
                'ok' => false,
                'outcome' => 'unavailable',
                'errors' => ['package_registry_unavailable'],
                'missing_tables' => $readiness['missing_tables'],
            ];
        }

        $package = SaPackage::query()->where('package_key', $packageKey)->where('kind', 'script')->first();
        if (! $package instanceof SaPackage) {
            return [
                'ok' => true,
                'outcome' => 'removed_on_server',
                'errors' => [],
                'package_key' => $packageKey,
                'script_id' => null,
            ];
        }

        $latest = SaPackageRelease::query()
            ->where('package_id', $package->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
        $manifest = $latest instanceof SaPackageRelease && is_array($latest->manifest) ? $latest->manifest : [];
        $scriptId = trim((string) ($manifest['script_id'] ?? $package->package_key));
        $digest = $latest instanceof SaPackageRelease ? (string) $latest->content_digest : null;
        $effectiveSource = trim((string) ($package->sync_source ?? $source));
        if ($effectiveSource === '') {
            $effectiveSource = ScriptTombstoneService::SOURCE_RPA_ADMIN;
        }

        $dependencies = $this->collectDependencies((string) $package->package_key);
        $historyRefs = $this->collectHistoryRefs((string) $package->package_key);
        $gate = $this->policy->evaluate($dependencies, $historyRefs);
        if (! ($gate['ok'] ?? false)) {
            return [
                'ok' => false,
                'outcome' => 'blocked',
                'errors' => $gate['errors'],
                'dependencies' => $gate['dependencies'],
                'history_refs' => $gate['history_refs'],
                'script_id' => $scriptId,
                'package_key' => $packageKey,
            ];
        }

        try {
            DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $package,
                $scriptId,
                $effectiveSource,
                $digest,
                $userId,
            ): void {
                $releaseIds = SaPackageRelease::query()
                    ->where('package_id', $package->id)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                $this->media->deleteForReleases($releaseIds);

                if ($releaseIds !== []) {
                    SaPackageDependency::query()->whereIn('release_id', $releaseIds)->delete();
                    SaPackageRelease::query()->whereIn('id', $releaseIds)->delete();
                }

                $package->delete();

                $this->tombstones->record(
                    $scriptId,
                    $effectiveSource,
                    (string) $package->package_key,
                    $digest,
                    $userId,
                );
            });
        } catch (Throwable $e) {
            Log::error('Social Automation Script removal failed.', [
                'connection' => SocialAutomationServiceConfig::CONNECTION,
                'package_key' => $packageKey,
                'exception' => $e->getMessage(),
            ]);

            return ['ok' => false, 'outcome' => 'unavailable', 'errors' => ['script_remove_failed'], 'package_key' => $packageKey];
        }

        return [
            'ok' => true,
            'outcome' => 'removed_on_server',
            'errors' => [],
            'script_id' => $scriptId,
            'package_key' => $packageKey,
        ];
    }

    /**
     * @return list<array{package_key: string, name: string, kind: string}>
     */
    private function collectDependencies(string $scriptPackageKey): array
    {
        $out = [];
        foreach ($this->ui->listWidgetsForUi() as $widget) {
            $scriptKey = trim((string) ($widget['script_key'] ?? ''));
            if ($scriptKey === $scriptPackageKey) {
                $out[] = [
                    'package_key' => (string) $widget['package_key'],
                    'name' => (string) $widget['name'],
                    'kind' => 'widget',
                ];
            }
        }

        $widgetKeys = array_column(
            array_filter($out, static fn (array $r): bool => $r['kind'] === 'widget'),
            'package_key',
        );

        foreach ($this->ui->listWorkflowsForUi() as $workflow) {
            $steps = is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [];
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $widgetKey = trim((string) ($step['widget_key'] ?? $step['package_key'] ?? ''));
                if ($widgetKey !== '' && in_array($widgetKey, $widgetKeys, true)) {
                    $out[] = [
                        'package_key' => (string) $workflow['package_key'],
                        'name' => (string) $workflow['name'],
                        'kind' => 'workflow',
                    ];
                    break;
                }
            }
        }

        // Dependency table edges onto this script.
        $depReleaseIds = SaPackageDependency::query()
            ->where('dependency_package_key', $scriptPackageKey)
            ->pluck('release_id')
            ->all();
        if ($depReleaseIds !== []) {
            $packageIds = SaPackageRelease::query()->whereIn('id', $depReleaseIds)->pluck('package_id')->all();
            foreach (SaPackage::query()->whereIn('id', $packageIds)->get() as $pkg) {
                if ((string) $pkg->kind === 'script') {
                    continue;
                }
                $out[] = [
                    'package_key' => (string) $pkg->package_key,
                    'name' => (string) $pkg->name,
                    'kind' => (string) $pkg->kind,
                ];
            }
        }

        $unique = [];
        foreach ($out as $row) {
            $unique[$row['kind'].'|'.$row['package_key']] = $row;
        }

        return array_values($unique);
    }

    /**
     * @return list<array{ref_type: string, ref_id: string}>
     */
    private function collectHistoryRefs(string $packageKey): array
    {
        $refs = [];
        try {
            if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_tasks')) {
                foreach (SaTask::query()->where('package_key', $packageKey)->limit(20)->get() as $task) {
                    $refs[] = ['ref_type' => 'task', 'ref_id' => (string) $task->task_uuid];
                }
            }
            if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_jobs')) {
                foreach (SaJob::query()->where('package_key', $packageKey)->limit(20)->get() as $job) {
                    $refs[] = ['ref_type' => 'job', 'ref_id' => (string) $job->job_uuid];
                }
            }
        } catch (Throwable) {
            return $refs;
        }

        return $refs;
    }

    /**
     * @return array{ok: bool, missing_tables: list<string>, exception: null|string}
     */
    private function readiness(): array
    {
        $required = ['sa_packages', 'sa_package_releases', 'sa_package_dependencies', 'sa_script_tombstones'];

        try {
            $schema = Schema::connection(SocialAutomationServiceConfig::CONNECTION);
            $missing = array_values(array_filter(
                $required,
                static fn (string $table): bool => ! $schema->hasTable($table),
            ));

            return ['ok' => $missing === [], 'missing_tables' => $missing, 'exception' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'missing_tables' => $required, 'exception' => $e->getMessage()];
        }
    }
}
