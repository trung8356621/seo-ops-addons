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
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function publish(array $input): PackagePublishResult
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

        if (! $this->tablesReady()) {
            return new PackagePublishResult(
                PackagePublishResult::OUTCOME_UNAVAILABLE,
                errors: ['package_registry_unavailable'],
            );
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($data): PackagePublishResult {
                $package = SaPackage::query()->firstOrCreate(
                    ['package_key' => $data['package_key']],
                    [
                        'kind' => $data['kind'],
                        'name' => $data['name'],
                        'description' => $data['description'],
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

                $release = SaPackageRelease::query()->create([
                    'package_id' => $package->id,
                    'version' => $data['version'],
                    'content_digest' => $data['content_digest'],
                    'schema_version' => $data['schema_version'],
                    'runtime_compatibility' => $data['runtime_compatibility'],
                    'manifest' => $data['manifest'],
                    'status' => 'published',
                    'published_at' => now(),
                ]);

                foreach ($data['dependencies'] as $dep) {
                    SaPackageDependency::query()->create([
                        'release_id' => $release->id,
                        'dependency_package_key' => $dep['package_key'],
                        'dependency_version' => $dep['version'],
                        'dependency_digest' => $dep['content_digest'],
                    ]);
                }

                return new PackagePublishResult(
                    PackagePublishResult::OUTCOME_CREATED,
                    release: $this->releaseArray($package, $release, idempotent: false),
                );
            });
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
                    'latest_version' => $latest?->version,
                    'latest_digest' => $latest?->content_digest,
                ];
            })
            ->all();
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
