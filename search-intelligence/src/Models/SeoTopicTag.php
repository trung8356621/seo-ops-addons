<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SearchFoundation\Models\Tag;

/**
 * Pivot: Topic ↔ user Tag (keyword_tags vocabulary).
 */
final class SeoTopicTag extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_tags';

    public $incrementing = false;

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
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}
