<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscMappingStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscQueryMappingType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiTopic;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoGscQueryMapping extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_query_mappings';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'property_id',
        'normalized_query',
        'identity_hash',
        'sample_query',
        'keyword_id',
        'cluster_id',
        'topic_id',
        'mapping_type',
        'confidence',
        'source',
        'status',
        'reason_codes',
        'metadata',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'property_id' => 'integer',
        'keyword_id' => 'integer',
        'cluster_id' => 'integer',
        'topic_id' => 'integer',
        'mapping_type' => GscQueryMappingType::class,
        'confidence' => 'decimal:2',
        'status' => GscMappingStatus::class,
        'reason_codes' => 'array',
        'metadata' => 'array',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
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

    /** @return BelongsTo<SeoKiTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SeoKiTopic::class, 'topic_id');
    }
}
