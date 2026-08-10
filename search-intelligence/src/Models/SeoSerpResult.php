<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpPageType;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpResultType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoSerpResult extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_serp_results';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'snapshot_id',
        'tenant_id',
        'site_id',
        'position',
        'result_type',
        'url',
        'normalized_url',
        'domain',
        'normalized_domain',
        'title',
        'snippet',
        'display_url',
        'page_type',
        'search_intent',
        'is_own_domain',
        'is_competitor',
        'is_featured',
        'is_sponsored',
        'published_at',
        'detected_language',
        'content_fingerprint',
        'metadata',
    ];

    protected $casts = [
        'snapshot_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'position' => 'integer',
        'result_type' => SerpResultType::class,
        'page_type' => SerpPageType::class,
        'is_own_domain' => 'boolean',
        'is_competitor' => 'boolean',
        'is_featured' => 'boolean',
        'is_sponsored' => 'boolean',
        'published_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
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

    /** @return HasMany<SeoSerpPageEvidence, $this> */
    public function pageEvidence(): HasMany
    {
        return $this->hasMany(SeoSerpPageEvidence::class, 'serp_result_id');
    }
}
