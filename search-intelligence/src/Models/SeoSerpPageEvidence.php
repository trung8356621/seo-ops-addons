<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpFetchStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpPageType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSerpPageEvidence extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_serp_page_evidence';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'snapshot_id',
        'serp_result_id',
        'url',
        'normalized_url',
        'domain',
        'fetch_status',
        'http_status',
        'page_type',
        'content_type',
        'search_intent',
        'title',
        'meta_description',
        'canonical_url',
        'headings',
        'entities',
        'schema_types',
        'content_summary',
        'word_count',
        'media_count',
        'table_count',
        'faq_count',
        'freshness_date',
        'content_hash',
        'analyzed_at',
        'confidence',
        'source',
        'error_code',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'snapshot_id' => 'integer',
        'serp_result_id' => 'integer',
        'fetch_status' => SerpFetchStatus::class,
        'http_status' => 'integer',
        'page_type' => SerpPageType::class,
        'headings' => 'array',
        'entities' => 'array',
        'schema_types' => 'array',
        'word_count' => 'integer',
        'media_count' => 'integer',
        'table_count' => 'integer',
        'faq_count' => 'integer',
        'freshness_date' => 'date',
        'analyzed_at' => 'datetime',
        'confidence' => 'decimal:2',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /** @return BelongsTo<SeoSerpSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SeoSerpSnapshot::class, 'snapshot_id');
    }

    /** @return BelongsTo<SeoSerpResult, $this> */
    public function serpResult(): BelongsTo
    {
        return $this->belongsTo(SeoSerpResult::class, 'serp_result_id');
    }
}
