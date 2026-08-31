<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SeoSiteSyncV3Receipt extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_v3_receipts';

    protected $fillable = [
        'run_id',
        'site_id',
        'resource',
        'processing_job_number',
        'cursor_before',
        'cursor_after',
        'item_count',
        'upsert_count',
        'delete_count',
        'checksum',
        'wp_request_ms',
        'decode_ms',
        'db_ms',
        'total_ms',
        'query_count',
        'status',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'cursor_before' => 'array',
        'cursor_after' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoSiteSyncRun::class, 'run_id');
    }
}
