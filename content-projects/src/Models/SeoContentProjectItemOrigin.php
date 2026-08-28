<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoContentProjectItemOrigin extends Model
{
    public const SOURCE_SEO_AUDIT = 'seo_audit';

    public const SOURCE_AI_NEW_CONTENT = 'ai_new_content';

    public const SOURCE_VOCABULARY_SUGGEST = 'vocabulary_suggest';

    public const SOURCE_MANUAL = 'manual';

    public $timestamps = false;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_item_origins';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'planner_run_id' => 'integer',
        'project_task_id' => 'integer',
        'source_article_id' => 'integer',
        'source_finding_ids' => 'array',
        'reason_codes' => 'array',
        'created_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'project_task_id');
    }

    public static function fingerprint(string $sourceType, int $articleId, array $reasonCodes): string
    {
        $codes = array_values(array_unique(array_map('strval', $reasonCodes)));
        sort($codes);

        return hash('sha256', $sourceType.'|'.$articleId.'|'.implode(',', $codes));
    }

    public static function planningFingerprint(string $sourceType, string $normalizedKeyword, string $normalizedTitle): string
    {
        return hash('sha256', $sourceType.'|'.$normalizedKeyword.'|'.$normalizedTitle);
    }
}
