<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicSourceType;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

/**
 * Shared topic persistence on omi_seeding — one execution topic = one social.
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
        'source_type',
        'max_comments_target',
        'member_count_at_share',
        'required_comments_per_user',
        'completed_comments',
        'shared_at',
        'archived_at',
        'paused_at',
        'cancelled_at',
        'completed_at',
    ];

    protected $casts = [
        'created_by' => 'integer',
        'links_json' => 'array',
        'status' => SeedingTopicStatus::class,
        'social_platform' => SeedingSocialPlatform::class,
        'source_type' => SeedingTopicSourceType::class,
        'max_comments_target' => 'integer',
        'member_count_at_share' => 'integer',
        'required_comments_per_user' => 'integer',
        'completed_comments' => 'integer',
        'shared_at' => 'datetime',
        'archived_at' => 'datetime',
        'paused_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
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
            ->whereNull('cancelled_at')
            ->whereIn('status', SeedingTopicStatus::feedVisibleValues())
            ->whereColumn('completed_comments', '<', 'max_comments_target');
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

    public function isPaused(): bool
    {
        return $this->status === SeedingTopicStatus::Paused
            || $this->paused_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->status === SeedingTopicStatus::Cancelled
            || $this->cancelled_at !== null;
    }

    public function isGloballyComplete(): bool
    {
        return $this->status === SeedingTopicStatus::Done
            || (int) $this->completed_comments >= $this->targetComments();
    }

    public function targetComments(): int
    {
        return max(1, (int) $this->max_comments_target);
    }

    public function requiredCommentsPerUser(): int
    {
        return max(1, (int) $this->required_comments_per_user);
    }

    public function progressPercent(): int
    {
        $target = $this->targetComments();
        $done = max(0, (int) $this->completed_comments);

        return (int) min(100, (int) floor(($done / $target) * 100));
    }
}
