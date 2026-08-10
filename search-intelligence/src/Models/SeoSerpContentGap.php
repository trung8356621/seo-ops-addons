<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpContentGapStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpContentGapType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSerpContentGap extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_serp_content_gaps';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'workspace_id',
        'cluster_id',
        'keyword_id',
        'snapshot_id',
        'gap_type',
        'scope',
        'entity',
        'topic',
        'heading',
        'question',
        'schema_type',
        'importance_score',
        'confidence',
        'evidence_result_refs',
        'evidence_urls',
        'recommended_action',
        'status',
        'metadata',
        'detected_at',
        'reviewed_at',
        'resolved_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'workspace_id' => 'integer',
        'cluster_id' => 'integer',
        'keyword_id' => 'integer',
        'snapshot_id' => 'integer',
        'gap_type' => SerpContentGapType::class,
        'importance_score' => 'decimal:2',
        'confidence' => 'decimal:2',
        'evidence_result_refs' => 'array',
        'evidence_urls' => 'array',
        'status' => SerpContentGapStatus::class,
        'metadata' => 'array',
        'detected_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
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

    /** @return BelongsTo<SeoKiKeyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(SeoKiKeyword::class, 'keyword_id');
    }

    /** @return BelongsTo<SeoSerpSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SeoSerpSnapshot::class, 'snapshot_id');
    }
}
