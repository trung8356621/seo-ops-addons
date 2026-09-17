<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * Pure Topic membership (one keyword per site → at most one Topic).
 */
final class SeoTopicKeyword extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_keywords';

    protected $fillable = [
        'site_id',
        'topic_id',
        'keyword_id',
        'source',
        'is_seed',
        'is_locked',
        'confidence',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'topic_id' => 'integer',
        'keyword_id' => 'integer',
        'is_seed' => 'boolean',
        'is_locked' => 'boolean',
        'confidence' => 'float',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SeoTopic::class, 'topic_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
