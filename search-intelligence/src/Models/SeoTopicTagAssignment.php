<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit Topic ↔ custom Tag assignment (no site_id; ownership via Topic/Tag.site_id).
 */
final class SeoTopicTagAssignment extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_tag_assignments';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = [
        'topic_id',
        'tag_id',
    ];

    protected $casts = [
        'topic_id' => 'integer',
        'tag_id' => 'integer',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SeoTopic::class, 'topic_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(SeoTopicTag::class, 'tag_id');
    }
}
