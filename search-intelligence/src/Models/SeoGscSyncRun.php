<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSearchType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSyncRunStatus;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoGscSyncRun extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_sync_runs';

    /** @var list<string> */
    protected $fillable = [
        'public_ref',
        'tenant_id',
        'site_id',
        'property_id',
        'provider_key',
        'operation_ref',
        'date_from',
        'date_to',
        'search_type',
        'dimensions',
        'filters',
        'status',
        'requested_rows',
        'received_rows',
        'persisted_rows',
        'skipped_rows',
        'failed_rows',
        'provider_request_count',
        'provider_cost',
        'started_at',
        'completed_at',
        'result_code',
        'warnings',
        'error_code',
        'error_message',
        'idempotency_hash',
        'created_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'property_id' => 'integer',
        'date_from' => 'date',
        'date_to' => 'date',
        'search_type' => GscSearchType::class,
        'dimensions' => 'array',
        'filters' => 'array',
        'status' => GscSyncRunStatus::class,
        'requested_rows' => 'integer',
        'received_rows' => 'integer',
        'persisted_rows' => 'integer',
        'skipped_rows' => 'integer',
        'failed_rows' => 'integer',
        'provider_request_count' => 'integer',
        'provider_cost' => 'decimal:4',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'warnings' => 'array',
        'created_by' => 'integer',
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
