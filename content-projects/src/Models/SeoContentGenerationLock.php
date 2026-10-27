<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoContentGenerationLock extends Model
{
    public const SLOT_GLOBAL = 'global';

    public const OWNER_GLOBAL_BATCH = 'global_batch';

    public const OWNER_PROJECT_RUN = 'project_run';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_generation_locks';

    protected $guarded = [];

    protected $casts = [
        'owner_id' => 'integer',
        'acquired_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'meta' => 'array',
    ];
}
