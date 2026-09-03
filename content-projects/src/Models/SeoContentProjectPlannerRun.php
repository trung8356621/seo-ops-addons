<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable planner run snapshot (filters / generation options + counts).
 * Rejection memory stays on seo_content_project_suggestion_decisions.
 */
class SeoContentProjectPlannerRun extends Model
{
    public const SOURCE_SEO_AUDIT = 'seo_audit';

    public const SOURCE_AI_NEW_CONTENT = 'ai_new_content';

    public const KIND_EXECUTED = 'executed';

    public const KIND_SAVED_CONFIG = 'saved_config';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    /** Automatic recovery in progress — same logical run, not terminal. */
    public const STATUS_RECOVERING = 'recovering';

    /** Waiting bounded backoff before automatic provider retry. */
    public const STATUS_WAITING_RETRY = 'waiting_retry';

    public const STATUS_COMPLETED = 'completed';

    /** Target not met after automatic recovery exhausted; accepted items preserved. */
    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_RECOVERING,
            self::STATUS_WAITING_RETRY,
        ];
    }
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_planner_runs';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'site_id' => 'integer',
        'requested_quantity' => 'integer',
        'configuration_snapshot' => 'array',
        'result_summary' => 'array',
        'prompt_result_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }
}
