<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpDevice;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpQueryStatus;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoSerpQuery extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_serp_queries';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'workspace_id',
        'keyword_id',
        'cluster_id',
        'query',
        'normalized_query',
        'identity_hash',
        'language',
        'country',
        'location',
        'device',
        'search_engine',
        'provider_key',
        'status',
        'latest_snapshot_ref',
        'settings',
        'metadata',
        'created_by',
        'archived_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'workspace_id' => 'integer',
        'keyword_id' => 'integer',
        'cluster_id' => 'integer',
        'device' => SerpDevice::class,
        'status' => SerpQueryStatus::class,
        'settings' => 'array',
        'metadata' => 'array',
        'created_by' => 'integer',
        'archived_at' => 'datetime',
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

    /** @return BelongsTo<SeoKiKeyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(SeoKiKeyword::class, 'keyword_id');
    }

    /** @return BelongsTo<SeoKeywordCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordCluster::class, 'cluster_id');
    }

    /** @return HasMany<SeoSerpSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(SeoSerpSnapshot::class, 'serp_query_id');
    }
}
