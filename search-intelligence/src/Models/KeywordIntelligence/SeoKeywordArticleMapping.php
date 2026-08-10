<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordArticleMappingType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoKeywordArticleMapping extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_article_mappings';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'keyword_id' => 'integer',
        'article_id' => 'integer',
        'mapping_type' => KeywordArticleMappingType::class,
        'rank_position' => 'integer',
        'is_primary' => 'boolean',
        'is_manual' => 'boolean',
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

    /** @return BelongsTo<SeoArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
