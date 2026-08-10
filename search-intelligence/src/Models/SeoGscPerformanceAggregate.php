<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPeriodType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscScopeType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoGscPerformanceAggregate extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_performance_aggregates';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'property_id',
        'scope_type',
        'scope_ref',
        'period_type',
        'date_from',
        'date_to',
        'comparison_date_from',
        'comparison_date_to',
        'clicks',
        'impressions',
        'ctr',
        'position',
        'clicks_delta',
        'impressions_delta',
        'ctr_delta',
        'position_delta',
        'query_count',
        'page_count',
        'summary',
        'calculated_at',
        'algorithm_version',
        'data_hash',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'property_id' => 'integer',
        'scope_type' => GscScopeType::class,
        'period_type' => GscPeriodType::class,
        'date_from' => 'date',
        'date_to' => 'date',
        'comparison_date_from' => 'date',
        'comparison_date_to' => 'date',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'decimal:6',
        'position' => 'decimal:3',
        'clicks_delta' => 'integer',
        'impressions_delta' => 'integer',
        'ctr_delta' => 'decimal:6',
        'position_delta' => 'decimal:3',
        'query_count' => 'integer',
        'page_count' => 'integer',
        'summary' => 'array',
        'calculated_at' => 'datetime',
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
