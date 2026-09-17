<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;

/**
 * Site-scoped Topic entity. name is the sole name SSOT.
 */
final class SeoTopic extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topics';

    protected $fillable = [
        'site_id',
        'name',
        'status',
        'is_locked',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'is_locked' => 'boolean',
    ];

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
}
