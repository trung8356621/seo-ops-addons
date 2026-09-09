<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

/**
 * Shared topic persistence on omi_seeding (commit point: Chia sẻ).
 */
class SeedingTopic extends Model
{
    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'seeding_topics';

    /** @var list<string> */
    protected $fillable = [
        'installation_id',
        'created_by',
        'created_by_display_name',
        'title',
        'full_text',
        'source_html',
        'social_url',
        'social_platform',
        'links_json',
        'status',
        'max_comments_target',
        'member_count_at_share',
        'required_comments_per_user',
        'shared_at',
        'archived_at',
    ];

    protected $casts = [
        'created_by' => 'integer',
        'links_json' => 'array',
        'status' => SeedingTopicStatus::class,
        'social_platform' => SeedingSocialPlatform::class,
        'max_comments_target' => 'integer',
        'member_count_at_share' => 'integer',
        'required_comments_per_user' => 'integer',
        'shared_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    /** @return HasMany<SeedingReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(SeedingReport::class, 'topic_id');
    }

    /** @param  Builder<static>  $query */
    public function scopeForInstallation(Builder $query, string $installationId): Builder
    {
        return $query->where('installation_id', $installationId);
    }

    /** @param  Builder<static>  $query */
    public function scopeSharedVisible(Builder $query): Builder
    {
        return $query
            ->whereNull('archived_at')
            ->whereIn('status', [
                SeedingTopicStatus::Shared->value,
                SeedingTopicStatus::Active->value,
            ]);
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

    public function isArchived(): bool
    {
        return $this->archived_at !== null
            || $this->status === SeedingTopicStatus::Archived;
    }

    public function requiredCommentsPerUser(): int
    {
        return max(1, (int) $this->required_comments_per_user);
    }
}
