<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoKeywordContentProjectLink extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_content_project_links';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'workspace_id' => 'integer',
        'topical_map_version_id' => 'integer',
        'topic_id' => 'integer',
        'cluster_id' => 'integer',
        'keyword_id' => 'integer',
        'conversion_id' => 'integer',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }
}
