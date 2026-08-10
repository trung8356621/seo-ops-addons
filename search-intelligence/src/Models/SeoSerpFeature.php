<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpFeatureType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSerpFeature extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_serp_features';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'snapshot_id',
        'tenant_id',
        'site_id',
        'feature_type',
        'position',
        'title',
        'text',
        'source_url',
        'source_domain',
        'question',
        'answer_excerpt',
        'metadata',
    ];

    protected $casts = [
        'snapshot_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'feature_type' => SerpFeatureType::class,
        'position' => 'integer',
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
}
