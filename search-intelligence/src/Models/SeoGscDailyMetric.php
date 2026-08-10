<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSearchType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoGscDailyMetric extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_daily_metrics';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'site_id',
        'property_id',
        'metric_date',
        'search_type',
        'query',
        'normalized_query',
        'normalized_query_hash',
        'page',
        'normalized_page',
        'normalized_page_hash',
        'country',
        'device',
        'search_appearance',
        'clicks',
        'impressions',
        'ctr',
        'position',
        'source',
        'source_ref',
        'data_hash',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'property_id' => 'integer',
        'metric_date' => 'date',
        'search_type' => GscSearchType::class,
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'decimal:6',
        'position' => 'decimal:3',
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
