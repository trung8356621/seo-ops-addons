<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTopicalLinkSuggestion extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topical_link_suggestions';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'topical_map_version_id' => 'integer',
        'source_article_id' => 'integer',
        'source_cluster_id' => 'integer',
        'target_article_id' => 'integer',
        'target_cluster_id' => 'integer',
        'anchor_keyword_id' => 'integer',
        'priority' => 'decimal:2',
        'confidence' => 'decimal:2',
        'reason_codes' => 'array',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }
}
