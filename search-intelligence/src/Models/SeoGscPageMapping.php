<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscMappingStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPageMappingType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoGscPageMapping extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_page_mappings';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'property_id',
        'page',
        'normalized_page',
        'identity_hash',
        'article_ref',
        'content_project_ref',
        'project_item_ref',
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
        'mapping_type' => GscPageMappingType::class,
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
}
