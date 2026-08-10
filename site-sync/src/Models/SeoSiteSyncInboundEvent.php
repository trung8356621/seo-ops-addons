<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncInboundEvent extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_inbound_events';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const STATUS_IGNORED_DUPLICATE = 'ignored_duplicate';

    public const STATUS_IGNORED_STALE = 'ignored_stale';

    protected $fillable = [
        'site_id',
        'event_id',
        'idempotency_key',
        'operation_id',
        'event_type',
        'wordpress_id',
        'status',
        'schema_version',
        'attempts',
        'last_error_code',
        'last_error_message',
        'retry_after',
        'hashes',
        'payload',
        'meta',
        'occurred_at',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'hashes' => 'array',
        'payload' => 'array',
        'meta' => 'array',
        'retry_after' => 'datetime',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
