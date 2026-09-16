<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoContentProjectTaskPlanningAttribution extends Model
{
    public const STATUS_ATTRIBUTED = 'attributed';

    public const STATUS_UNATTRIBUTED = 'unattributed';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_task_planning_attributions';

    protected $guarded = [];

    protected $casts = [
        'project_task_id' => 'integer',
        'site_id' => 'integer',
        'planner_run_id' => 'integer',
        'source_keyword_id' => 'integer',
        'dna_phrases' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'project_task_id');
    }

    public function isAttributed(): bool
    {
        return $this->attribution_status === self::STATUS_ATTRIBUTED
            && trim((string) ($this->cluster_ref ?? '')) !== '';
    }
}
