<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Xoá mềm khỏi Article AI History (không xoá article/run/run_item/task/revision).
 */
class SeoArticleAiHistoryTombstone extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_ai_history_tombstones';

    protected $guarded = [];

    protected $casts = [
        'article_id' => 'integer',
        'prompt_result_id' => 'integer',
        'run_id' => 'integer',
        'run_item_id' => 'integer',
        'attempt' => 'integer',
        'deleted_by' => 'integer',
        'deleted_at' => 'datetime',
        'meta' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
