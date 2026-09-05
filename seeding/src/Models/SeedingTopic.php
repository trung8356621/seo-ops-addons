<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\LinkIntelligence\Models\LinkResource;

class SeedingTopic extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seeding_topics';

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'created_by',
        'full_text',
        'source_html',
        'social_url',
        'social_platform',
        'status',
        'published_at',
        'archived_at',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'created_by' => 'integer',
        'status' => SeedingTopicStatus::class,
        'social_platform' => SeedingSocialPlatform::class,
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /** @return BelongsToMany<LinkResource, $this> */
    public function linkResources(): BelongsToMany
    {
        return $this->belongsToMany(
            LinkResource::class,
            'seeding_topic_links',
            'topic_id',
            'link_resource_id',
        )->withTimestamps();
    }

    /** @param  Builder<static>  $query */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    /** @param  Builder<static>  $query */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function preview(int $max = 80): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $this->full_text) ?? $this->full_text);
        if ($text === '') {
            return 'Chủ đề mới';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max).'…';
    }

    public function isDraft(): bool
    {
        return $this->status === SeedingTopicStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === SeedingTopicStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
