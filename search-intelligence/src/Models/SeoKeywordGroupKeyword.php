<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * One keyword belongs to at most one Group per site.
 */
final class SeoKeywordGroupKeyword extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_group_keywords';

    protected $fillable = [
        'site_id',
        'group_id',
        'keyword_id',
        'source',
        'similarity_score',
        'is_topic_candidate',
        'topic_candidate_override',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'group_id' => 'integer',
        'keyword_id' => 'integer',
        'similarity_score' => 'float',
        'is_topic_candidate' => 'boolean',
        // topic_candidate_override stays uncast — nullable bool (null ≠ false).
    ];

    public function topicCandidateOverride(): ?bool
    {
        if (! array_key_exists('topic_candidate_override', $this->attributes)) {
            return null;
        }
        $raw = $this->attributes['topic_candidate_override'];
        if ($raw === null) {
            return null;
        }

        return (bool) $raw;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordGroup::class, 'group_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
