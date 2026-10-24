<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageDependency;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Immutable package registry — publish + read. Never executes package contents.
 */
final class PackageRegistryService
{
    public function __construct(
        private readonly PackagePublishValidator $validator,
        private readonly PackageDependencyValidator $dependencyValidator = new PackageDependencyValidator,
        private readonly ?WidgetInvalidationService $widgetInvalidation = null,
        private readonly ?ScriptTombstoneService $tombstones = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array{user_id?: int|null, sync_source?: string|null}  $audit
     */
    public function publish(array $input, array $audit = []): PackagePublishResult
    {
        $validated = $this->validator->validate($input);
        if (! ($validated['ok'] ?? false)) {
            return new PackagePublishResult(
                PackagePublishResult::OUTCOME_VALIDATION,
                errors: $validated['errors'] ?? ['validation_failed'],
            );
        }

        /** @var array<string, mixed> $data */
        $data = $validated['data'];
        $userId = isset($audit['user_id']) ? (int) $audit['user_id'] : null;
        $syncSource = isset($audit['sync_source']) ? trim((string) $audit['sync_source']) : null;

        if (! $this->tablesReady()) {
            return new PackagePublishResult(
                PackagePublishResult::OUTCOME_UNAVAILABLE,
                errors: ['package_registry_unavailable'],
            );
        }

        if ((string) $data['kind'] === 'script') {
            $manifest = is_array($data['manifest'] ?? null) ? $data['manifest'] : [];
            $scriptId = trim((string) ($manifest['script_id'] ?? $data['package_key'] ?? ''));
            $source = $syncSource !== null && $syncSource !== ''
                ? $syncSource
                : ScriptTombstoneService::SOURCE_RPA_ADMIN;
            $tombstones = $this->tombstones ?? app(ScriptTombstoneService::class);
            if ($scriptId !== '' && $tombstones->isSuppressed($scriptId, $source)) {
                return new PackagePublishResult(
                    PackagePublishResult::OUTCOME_REMOVED_ON_SERVER,
                    errors: ['removed_on_server'],
                );
            }
        }

        try {
            $result = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($data, $userId, $syncSource): PackagePublishResult {
                $package = SaPackage::query()->firstOrCreate(
                    ['package_key' => $data['package_key']],
                    [
                        'kind' => $data['kind'],
                        'name' => $data['name'],
                        'description' => $data['description'],
                        'is_enabled' => true,
                        'created_by_user_id' => $userId,
                        'sync_source' => $syncSource,
                    ],
                );

                if ((string) $package->kind !== (string) $data['kind']) {
                    return new PackagePublishResult(
                        PackagePublishResult::OUTCOME_CONFLICT,
                        errors: ['package_kind_conflict'],
                    );
                }

                // Keep display name/description fresh for existing package keys.
                $package->fill([
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'updated_by_user_id' => $userId,
                    'sync_source' => $syncSource ?? $package->sync_source,
                ])->save();

                $existing = SaPackageRelease::query()
                    ->where('package_id', $package->id)
                    ->where('version', $data['version'])
                    ->first();

                if ($existing instanceof SaPackageRelease) {
                    if (PackageDigest::equals((string) $existing->content_digest, (string) $data['content_digest'])) {
                        return new PackagePublishResult(
                            PackagePublishResult::OUTCOME_IDEMPOTENT,
                            release: $this->releaseArray($package, $existing, idempotent: true),
                        );
                    }

                    return new PackagePublishResult(
                        PackagePublishResult::OUTCOME_CONFLICT,
                        errors: ['version_digest_conflict'],
                        existingDigest: (string) $existing->content_digest,
                    );
                }

                $previous = SaPackageRelease::query()
                    ->where('package_id', $package->id)
                    ->where('status', 'published')
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->first();

                $depCheck = $this->validatePublishedDependencies($data['dependencies']);
                if (! ($depCheck['ok'] ?? false)) {
                    return new PackagePublishResult(
                        PackagePublishResult::OUTCOME_VALIDATION,
                        errors: $depCheck['errors'] ?? ['dependencies_unresolved'],
                    );
                }

                $executionDigest = null;
                if ((string) $data['kind'] === 'script') {
                    $executionDigest = ScriptExecutionDigest::fromManifest(
                        is_array($data['manifest']) ? $data['manifest'] : []
                    );
                }

                $isScript = (string) $data['kind'] === 'script';
                $release = SaPackageRelease::query()->create([
                    'package_id' => $package->id,
                    'version' => $data['version'],
                    'content_digest' => $data['content_digest'],
                    'execution_digest' => $executionDigest,
                    'schema_version' => $data['schema_version'],
                    'runtime_compatibility' => $data['runtime_compatibility'],
                    'manifest' => $data['manifest'],
                    'status' => $isScript ? 'pending_media' : 'published',
                    'published_at' => $isScript ? null : now(),
                    'created_by_user_id' => $userId,
                    'sync_source' => $syncSource,
                ]);

                foreach ($data['dependencies'] as $dep) {
                    SaPackageDependency::query()->create([
                        'release_id' => $release->id,
                        'dependency_package_key' => $dep['package_key'],
                        'dependency_version' => $dep['version'],
                        'dependency_digest' => $dep['content_digest'],
                    ]);
                }

                $publishResult = new PackagePublishResult(
                    PackagePublishResult::OUTCOME_CREATED,
                    release: $this->releaseArray($package, $release, idempotent: false),
                );

                if ((string) $data['kind'] === 'script' && $executionDigest !== null) {
                    $prevManifest = $previous instanceof SaPackageRelease && is_array($previous->manifest)
                        ? $previous->manifest
                        : [];
                    $newManifest = is_array($data['manifest']) ? $data['manifest'] : [];
                    $executionChanged = $previous instanceof SaPackageRelease
                        ? ScriptExecutionDigest::executionChanged($prevManifest, $newManifest)
                        : false;

                    $invalidation = $this->widgetInvalidation ?? app(WidgetInvalidationService::class);
                    $disabled = $invalidation->invalidateForScriptPublish(
                        (string) $package->package_key,
                        (string) $data['version'],
                        (string) $data['content_digest'],
                        $executionDigest,
                        $previous?->version,
                        $previous?->content_digest,
                        $previous?->execution_digest,
                        $executionChanged,
                    );
                    $releaseArr = $publishResult->release ?? [];
                    $releaseArr['widgets_disabled'] = $disabled;
                    $publishResult = new PackagePublishResult(
                        PackagePublishResult::OUTCOME_CREATED,
                        release: $releaseArr,
                    );
                }

                return $publishResult;
            });

            return $result;
        } catch (Throwable) {
            return new PackagePublishResult(
                PackagePublishResult::OUTCOME_UNAVAILABLE,
                errors: ['package_registry_write_failed'],
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPackages(): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        return SaPackage::query()
            ->orderBy('package_key')
            ->get()
            ->map(static function (SaPackage $package): array {
                $latest = SaPackageRelease::query()
                    ->where('package_id', $package->id)
                    ->where('status', 'published')
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->first();

                return [
                    'package_key' => $package->package_key,
                    'kind' => $package->kind,
                    'name' => $package->name,
                    'description' => $package->description,
                    'is_enabled' => (bool) ($package->is_enabled ?? true),
                    'disabled_reason' => $package->disabled_reason ?? null,
                    'latest_version' => $latest?->version,
                    'latest_digest' => $latest?->content_digest,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByKind(string $kind): array
    {
        return array_values(array_filter(
            $this->listPackages(),
            static fn (array $row): bool => strtolower((string) ($row['kind'] ?? '')) === strtolower($kind)
        ));
    }

    /**
     * @return array{package_key: string, kind: string, is_enabled: bool, disabled_reason: string|null}|null
     */
    public function packageState(string $packageKey): ?array
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $package = SaPackage::query()->where('package_key', $packageKey)->first();
        if (! $package instanceof SaPackage) {
            return null;
        }

        return [
            'package_key' => (string) $package->package_key,
            'kind' => (string) $package->kind,
            'is_enabled' => (bool) ($package->is_enabled ?? true),
            'disabled_reason' => $package->disabled_reason !== null ? (string) $package->disabled_reason : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRelease(string $packageKey, string $version): ?array
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $package = SaPackage::query()->where('package_key', $packageKey)->first();
        if (! $package instanceof SaPackage) {
            return null;
        }

        $release = SaPackageRelease::query()
            ->where('package_id', $package->id)
            ->where('version', $version)
            ->first();

        if (! $release instanceof SaPackageRelease) {
            return null;
        }

        $deps = SaPackageDependency::query()
            ->where('release_id', $release->id)
            ->get()
            ->map(static fn (SaPackageDependency $d): array => [
                'package_key' => $d->dependency_package_key,
                'version' => $d->dependency_version,
                'content_digest' => $d->dependency_digest,
            ])
            ->all();

        return [
            'package_key' => $package->package_key,
            'kind' => $package->kind,
            'name' => $package->name,
            'description' => $package->description,
            'version' => $release->version,
            'content_digest' => $release->content_digest,
            'schema_version' => $release->schema_version,
            'runtime_compatibility' => $release->runtime_compatibility,
            'manifest' => $release->manifest,
            'status' => $release->status,
            'dependencies' => $deps,
            'published_at' => $release->published_at?->toIso8601String(),
        ];
    }

    private function tablesReady(): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_packages')
                && Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_package_releases');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array{package_key: string, version: string, content_digest: string}>  $dependencies
     * @return array{ok: bool, errors: list<string>}
     */
    private function validatePublishedDependencies(array $dependencies): array
    {
        if ($dependencies === []) {
            return ['ok' => true, 'errors' => []];
        }

        $catalog = [];
        foreach ($dependencies as $dep) {
            $pkg = SaPackage::query()->where('package_key', $dep['package_key'])->first();
            if (! $pkg instanceof SaPackage) {
                continue;
            }
            $release = SaPackageRelease::query()
                ->where('package_id', $pkg->id)
                ->where('version', $dep['version'])
                ->where('status', 'published')
                ->first();
            if (! $release instanceof SaPackageRelease) {
                continue;
            }
            $catalog[] = [
                'package_key' => (string) $pkg->package_key,
                'version' => (string) $release->version,
                'content_digest' => (string) $release->content_digest,
                'status' => (string) $release->status,
            ];
        }

        return $this->dependencyValidator->validate($dependencies, $catalog);
    }

    /**
     * @return array{
     *     package_key: string,
     *     kind: string,
     *     name: string,
     *     version: string,
     *     content_digest: string,
     *     schema_version: int,
     *     status: string,
     *     idempotent: bool
     * }
     */
    private function releaseArray(SaPackage $package, SaPackageRelease $release, bool $idempotent): array
    {
        return [
            'package_key' => $package->package_key,
            'kind' => $package->kind,
            'name' => $package->name,
            'version' => $release->version,
            'content_digest' => $release->content_digest,
            'schema_version' => (int) $release->schema_version,
            'status' => $release->status,
            'idempotent' => $idempotent,
        ];
    }
}
