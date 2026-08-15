<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

final class SeoKeywordClassification extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_classifications';

    protected $primaryKey = 'keyword_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'keyword_id' => 'integer',
        'canonical_keyword_id' => 'integer',
        'is_anchor_candidate' => 'boolean',
        'is_seo_keyword' => 'boolean',
        'is_ambiguous' => 'boolean',
        'is_dirty' => 'boolean',
        'anchor_priority' => 'integer',
        'classification_confidence' => 'float',
        'keyword_score' => 'float',
        'occurrence_count' => 'integer',
        'duplicate_of' => 'integer',
        'segments' => 'array',
        'classified_at' => 'datetime',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
