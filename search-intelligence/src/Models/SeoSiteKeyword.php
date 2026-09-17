<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * Per-site keyword classification — no Topic membership.
 */
final class SeoSiteKeyword extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_keywords';

    protected $fillable = [
        'site_id',
        'keyword_id',
        'phrase_kind',
        'seo_intent',
        'is_seo_keyword',
        'is_anchor_candidate',
        'is_ambiguous',
        'keyword_score',
        'confidence',
        'review_state',
        'source',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'keyword_id' => 'integer',
        'is_seo_keyword' => 'boolean',
        'is_anchor_candidate' => 'boolean',
        'is_ambiguous' => 'boolean',
        'keyword_score' => 'float',
        'confidence' => 'float',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
