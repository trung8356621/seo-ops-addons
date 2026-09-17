<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * DNA of a keyword inside a site Topic — rebuild on recluster; no legacy migrate.
 */
final class SeoTopicKeywordDna extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_keyword_dna';

    protected $fillable = [
        'site_id',
        'topic_id',
        'keyword_id',
        'value',
        'facet_type',
        'placement',
        'confidence',
        'source',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'topic_id' => 'integer',
        'keyword_id' => 'integer',
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
