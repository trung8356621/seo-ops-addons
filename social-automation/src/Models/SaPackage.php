<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * @property int $id
 * @property string $package_key
 * @property string $kind
 * @property string $name
 * @property string|null $description
 */
final class SaPackage extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_packages';

    protected $fillable = [
        'package_key',
        'kind',
        'name',
        'description',
        'is_enabled',
        'disabled_reason',
        'disabled_meta',
        'created_by_user_id',
        'updated_by_user_id',
        'sync_source',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'disabled_meta' => 'array',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    /** @return HasMany<SaPackageRelease, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(SaPackageRelease::class, 'package_id');
    }
}
