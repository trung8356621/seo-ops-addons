<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpDevice;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpSnapshotStatus;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SeoSerpSnapshot extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_serp_snapshots';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'serp_query_id',
        'provider_key',
        'provider_request_ref',
        'captured_at',
        'status',
        'result_count',
        'organic_result_count',
        'feature_count',
        'locale',
        'location',
        'device',
        'search_engine',
        'raw_checksum',
        'normalized_checksum',
        'summary',
        'analysis_summary',
        'error_code',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'serp_query_id' => 'integer',
        'captured_at' => 'datetime',
        'status' => SerpSnapshotStatus::class,
        'result_count' => 'integer',
        'organic_result_count' => 'integer',
        'feature_count' => 'integer',
        'device' => SerpDevice::class,
        'summary' => 'array',
        'analysis_summary' => 'array',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (SeoSerpSnapshot $snapshot): void {
            $snapshot->assertMutable();
        });
    }

    public function assertMutable(): void
    {
        $status = $this->getOriginal('status');

        if ($status instanceof SerpSnapshotStatus && $status->isImmutable()) {
            throw new LogicException('SERP snapshot is immutable after completion.');
        }

        if (is_string($status) && SerpSnapshotStatus::tryFrom($status)?->isImmutable()) {
            throw new LogicException('SERP snapshot is immutable after completion.');
        }
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /** @return BelongsTo<SeoSerpQuery, $this> */
    public function serpQuery(): BelongsTo
    {
        return $this->belongsTo(SeoSerpQuery::class, 'serp_query_id');
    }

    /** @return HasMany<SeoSerpResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(SeoSerpResult::class, 'snapshot_id');
    }

    /** @return HasMany<SeoSerpFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(SeoSerpFeature::class, 'snapshot_id');
    }

    /** @return HasMany<SeoSerpPageEvidence, $this> */
    public function pageEvidence(): HasMany
    {
        return $this->hasMany(SeoSerpPageEvidence::class, 'snapshot_id');
    }
}
