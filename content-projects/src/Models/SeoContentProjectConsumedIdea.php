<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoContentProjectConsumedIdea extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_consumed_ideas';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'source_keyword_id' => 'integer',
        'source_article_id' => 'integer',
        'project_task_id' => 'integer',
        'consumed_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'project_task_id');
    }

    public static function sourceRefForKeyword(int $keywordId): string
    {
        return (string) max(0, $keywordId);
    }
}
