<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoKeywordProjectConversion extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_project_conversions';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'workspace_id' => 'integer',
        'topical_map_version_id' => 'integer',
        'selected_cluster_refs' => 'array',
        'summary' => 'array',
        'created_by' => 'integer',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<SeoTopicalMapVersion, $this> */
    public function mapVersion(): BelongsTo
    {
        return $this->belongsTo(SeoTopicalMapVersion::class, 'topical_map_version_id');
    }
}
