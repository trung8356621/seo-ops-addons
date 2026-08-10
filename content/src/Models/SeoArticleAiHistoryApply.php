<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail cho «apply vào bản nháp editor» — không phải bằng chứng đã publish/lưu.
 * `committed=1` chỉ đánh dấu bản nháp đã được lưu vào bài viết (không đổi trạng thái workflow).
 */
class SeoArticleAiHistoryApply extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_ai_history_applies';

    protected $guarded = [];

    protected $casts = [
        'article_id' => 'integer',
        'prompt_result_id' => 'integer',
        'run_id' => 'integer',
        'run_item_id' => 'integer',
        'attempt' => 'integer',
        'applied_by' => 'integer',
        'applied_at' => 'datetime',
        'committed' => 'boolean',
        'provenance' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
