<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;

/**
 * Site-scoped Topic entity. name is the sole name SSOT.
 */
final class SeoTopic extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topics';

    protected $fillable = [
        'site_id',
        'keyword_group_id',
        'name',
        'source',
        'status',
        'is_locked',
        'mcp_excluded',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'keyword_group_id' => 'integer',
        'is_locked' => 'boolean',
        'mcp_excluded' => 'boolean',
    ];

    public function keywordGroup(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordGroup::class, 'keyword_group_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SeoTopicKeyword::class, 'topic_id');
    }

    public function dnaRows(): HasMany
    {
        return $this->hasMany(SeoTopicKeywordDna::class, 'topic_id');
    }

    public function isActive(): bool
    {
        return (string) $this->status === TopicStatus::ACTIVE;
    }

    public function isManual(): bool
    {
        return TopicSource::normalize($this->source ?? null) === TopicSource::MANUAL;
    }
}
