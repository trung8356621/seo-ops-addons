<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoLinkHealthRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_link_health_runs';

    protected $guarded = [];

    protected $casts = [
        'cursor' => 'integer',
        'posts_processed' => 'integer',
        'links_checked' => 'integer',
        'broken_candidates' => 'integer',
        'total_posts' => 'integer',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
