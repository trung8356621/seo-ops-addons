<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTopicalMapVersion extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topical_map_versions';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'version' => 'integer',
        'snapshot' => 'array',
        'summary' => 'array',
        'generated_by' => 'integer',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
        'approved_by' => 'integer',
        'superseded_by_version_id' => 'integer',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }
}
