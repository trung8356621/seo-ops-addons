<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lịch sử hành động review bài viết (submit/approve/archive/...).
 * Bảng `seo_article_reviews`, connection `omi_seo_ai`.
 */
class SeoArticleReview extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_reviews';

    protected $guarded = [];

    protected $casts = [
        'article_id' => 'integer',
        'reviewer_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'reviewer_id');
    }
}
