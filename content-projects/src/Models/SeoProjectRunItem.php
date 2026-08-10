<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemKind;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectRunItemClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoProjectRunItem extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_run_items';

    protected $guarded = [];

    protected $casts = [
        'run_id' => 'integer',
        'task_id' => 'integer',
        'article_id' => 'integer',
        'attempt' => 'integer',
        'input_snapshot' => 'array',
        'output_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoProjectRun::class, 'run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'task_id');
    }

    /**
     * Run history audit — gồm soft-deleted task.
     */
    public function taskIncludingDeleted(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'task_id')->withTrashed();
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function kind(): SeoProjectRunItemKind
    {
        return SeoProjectRunItemClassifier::classify(
            $this->action !== null ? (string) $this->action : null
        );
    }

    /**
     * Article pipeline rows (SeoProjectRunAction.*) — counters / dispatch / finalize gate.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeArticleExecution(Builder $query): Builder
    {
        return $query->whereIn('action', SeoProjectRunItemClassifier::articleActionValues());
    }

    /**
     * Workflow step retry rows (action LIKE step:%).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWorkflowStep(Builder $query): Builder
    {
        return $query->where('action', 'like', SeoProjectRunItemClassifier::STEP_ACTION_PREFIX.'%');
    }

    /**
     * Step + helper/control — không phải article execution.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHelperOrControl(Builder $query): Builder
    {
        return $query->whereNotIn('action', SeoProjectRunItemClassifier::articleActionValues());
    }
}
