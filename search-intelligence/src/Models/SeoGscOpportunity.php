<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscScopeType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoGscOpportunity extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_opportunities';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'property_id',
        'opportunity_type',
        'scope_type',
        'scope_ref',
        'query_mapping_ref',
        'page_mapping_ref',
        'risk_level',
        'priority_score',
        'confidence',
        'date_from',
        'date_to',
        'comparison_date_from',
        'comparison_date_to',
        'evidence',
        'reason_codes',
        'recommended_action',
        'status',
        'reviewed_by',
        'reviewed_at',
        'resolved_at',
        'resolution_code',
        'fingerprint',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'property_id' => 'integer',
        'opportunity_type' => GscOpportunityType::class,
        'scope_type' => GscScopeType::class,
        'priority_score' => 'decimal:2',
        'confidence' => 'decimal:2',
        'date_from' => 'date',
        'date_to' => 'date',
        'comparison_date_from' => 'date',
        'comparison_date_to' => 'date',
        'evidence' => 'array',
        'reason_codes' => 'array',
        'status' => GscOpportunityStatus::class,
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /** @return BelongsTo<SeoGscProperty, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(SeoGscProperty::class, 'property_id');
    }
}
