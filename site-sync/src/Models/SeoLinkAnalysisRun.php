<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoLinkAnalysisRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_link_analysis_runs';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'cursor' => 'integer',
        'processed_posts' => 'integer',
        'total_posts' => 'integer',
        'opportunities' => 'integer',
        'orphan_pages' => 'integer',
        'internal_links' => 'integer',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
