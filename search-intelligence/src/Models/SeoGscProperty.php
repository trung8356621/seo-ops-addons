<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSearchType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoGscProperty extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_properties';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'provider_key',
        'property_uri',
        'identity_hash',
        'property_type',
        'display_name',
        'status',
        'sync_enabled',
        'default_country',
        'default_search_type',
        'timezone',
        'last_synced_at',
        'last_complete_date',
        'last_error_code',
        'last_error_message',
        'settings',
        'metadata',
        'legacy_mapping_id',
        'created_by',
        'archived_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'property_type' => GscPropertyType::class,
        'status' => GscPropertyStatus::class,
        'sync_enabled' => 'boolean',
        'default_search_type' => GscSearchType::class,
        'last_synced_at' => 'datetime',
        'last_complete_date' => 'date',
        'settings' => 'array',
        'metadata' => 'array',
        'legacy_mapping_id' => 'integer',
        'created_by' => 'integer',
        'archived_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /** @return HasMany<SeoGscSyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SeoGscSyncRun::class, 'property_id');
    }

    /** @return HasMany<SeoGscDailyMetric, $this> */
    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(SeoGscDailyMetric::class, 'property_id');
    }

    /** @return HasMany<SeoGscQueryMapping, $this> */
    public function queryMappings(): HasMany
    {
        return $this->hasMany(SeoGscQueryMapping::class, 'property_id');
    }

    /** @return HasMany<SeoGscPageMapping, $this> */
    public function pageMappings(): HasMany
    {
        return $this->hasMany(SeoGscPageMapping::class, 'property_id');
    }

    /** @return HasMany<SeoGscPerformanceAggregate, $this> */
    public function performanceAggregates(): HasMany
    {
        return $this->hasMany(SeoGscPerformanceAggregate::class, 'property_id');
    }

    /** @return HasMany<SeoGscOpportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(SeoGscOpportunity::class, 'property_id');
    }
}
