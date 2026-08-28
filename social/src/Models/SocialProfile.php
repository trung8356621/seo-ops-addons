<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\Social\Enums\SocialPlatform;

class SocialProfile extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_social_profiles';

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'platform',
        'display_name',
        'profile_url',
        'is_active',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'platform' => SocialPlatform::class,
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /** @param  Builder<static>  $query */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
