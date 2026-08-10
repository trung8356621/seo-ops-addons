<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoPromptResultLink extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_prompt_result_links';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public function promptResult(): BelongsTo
    {
        return $this->belongsTo(SeoPromptResult::class, 'prompt_result_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function projectRun(): BelongsTo
    {
        return $this->belongsTo(SeoProjectRun::class, 'project_run_id');
    }

    public function projectTask(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'project_task_id');
    }
}

