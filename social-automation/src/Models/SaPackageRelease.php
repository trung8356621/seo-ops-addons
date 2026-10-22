<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Immutable published package version.
 *
 * @property int $id
 * @property int $package_id
 * @property string $version
 * @property string $content_digest
 * @property int $schema_version
 * @property array<string, mixed>|null $runtime_compatibility
 * @property array<string, mixed> $manifest
 * @property string $status
 */
final class SaPackageRelease extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_package_releases';

    protected $fillable = [
        'package_id',
        'version',
        'content_digest',
        'execution_digest',
        'schema_version',
        'runtime_compatibility',
        'manifest',
        'status',
        'published_at',
        'created_by_user_id',
        'sync_source',
    ];

    protected function casts(): array
    {
        return [
            'runtime_compatibility' => 'array',
            'manifest' => 'array',
            'schema_version' => 'integer',
            'published_at' => 'datetime',
            'created_by_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<SaPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(SaPackage::class, 'package_id');
    }

    /** @return HasMany<SaPackageDependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(SaPackageDependency::class, 'release_id');
    }
}
