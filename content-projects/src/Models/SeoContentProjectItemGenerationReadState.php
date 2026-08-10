<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user inbox read-state for a content-project item generation completion.
 *
 * Compared against latest successful generation finished_at — not a permanent boolean.
 */
class SeoContentProjectItemGenerationReadState extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_item_generation_read_states';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'project_id' => 'integer',
        'project_item_id' => 'integer',
        'viewed_generation_completed_at' => 'datetime',
        'viewed_at' => 'datetime',
    ];
}
