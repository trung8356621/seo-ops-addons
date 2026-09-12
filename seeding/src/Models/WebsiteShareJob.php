<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\Seeding\Enums\WebsiteShareJobStatus;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

class WebsiteShareJob extends Model
{
    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'website_share_jobs';

    /** @var list<string> */
    protected $fillable = [
        'installation_id',
        'article_id',
        'site_id',
        'index_generation',
        'source_type',
        'status',
        'title',
        'article_url',
        'domain',
        'thumbnail_url',
        'indexed_at',
        'eligible_at',
        'share_content',
        'content_generated_at',
        'cancelled_at',
        'completed_at',
    ];

    protected $casts = [
        'article_id' => 'integer',
        'site_id' => 'integer',
        'status' => WebsiteShareJobStatus::class,
        'indexed_at' => 'datetime',
        'eligible_at' => 'datetime',
        'content_generated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return HasMany<WebsiteShareTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(WebsiteShareTarget::class, 'job_id');
    }

    /** @return HasMany<WebsiteShareReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(WebsiteShareReport::class, 'job_id');
    }

    /** @param  Builder<static>  $query */
    public function scopeForInstallation(Builder $query, string $installationId): Builder
    {
        return $query->where('installation_id', $installationId);
    }

    /** @param  Builder<static>  $query */
    public function scopeFeedVisible(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            WebsiteShareJobStatus::Cancelled->value,
        ]);
    }
}
