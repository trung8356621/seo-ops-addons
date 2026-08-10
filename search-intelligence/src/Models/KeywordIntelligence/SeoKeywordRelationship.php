<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordRelationshipType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoKeywordRelationship extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_relationships';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'keyword_id' => 'integer',
        'related_keyword_id' => 'integer',
        'relationship_type' => KeywordRelationshipType::class,
        'confidence' => 'decimal:2',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<SeoKiKeyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(SeoKiKeyword::class, 'keyword_id');
    }

    /** @return BelongsTo<SeoKiKeyword, $this> */
    public function relatedKeyword(): BelongsTo
    {
        return $this->belongsTo(SeoKiKeyword::class, 'related_keyword_id');
    }
}
