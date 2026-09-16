<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Topic planning history event: one Topic + Target DNA count per successful planner run.
 */
class SeoContentProjectTopicHistory extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_topic_histories';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'planning_month' => 'date',
        'planned_article_count' => 'integer',
        'planner_run_id' => 'integer',
    ];
}
