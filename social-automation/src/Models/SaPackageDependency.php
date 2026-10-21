<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * @property int $id
 * @property int $release_id
 * @property string $dependency_package_key
 * @property string $dependency_version
 * @property string $dependency_digest
 */
final class SaPackageDependency extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_package_dependencies';

    protected $fillable = [
        'release_id',
        'dependency_package_key',
        'dependency_version',
        'dependency_digest',
    ];

    /** @return BelongsTo<SaPackageRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(SaPackageRelease::class, 'release_id');
    }
}
