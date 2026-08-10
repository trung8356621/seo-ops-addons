<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpClusterEvidenceStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpPageType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSerpClusterEvidence extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_serp_cluster_evidence';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'workspace_id',
        'cluster_id',
        'snapshot_refs',
        'observed_intent',
        'observed_page_types',
        'dominant_page_type',
        'serp_overlap_score',
        'intent_consistency_score',
        'cluster_confidence',
        'recommended_action',
        'recommended_content_type',
        'reason_codes',
        'warnings',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'workspace_id' => 'integer',
        'cluster_id' => 'integer',
        'snapshot_refs' => 'array',
        'observed_page_types' => 'array',
        'dominant_page_type' => SerpPageType::class,
        'serp_overlap_score' => 'decimal:2',
        'intent_consistency_score' => 'decimal:2',
        'cluster_confidence' => 'decimal:2',
        'reason_codes' => 'array',
        'warnings' => 'array',
        'status' => SerpClusterEvidenceStatus::class,
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<SeoKeywordCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordCluster::class, 'cluster_id');
    }
}
