<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Current Index Health aggregate per article.
 *
 * @property int $id
 * @property int $article_id
 * @property int $site_id
 * @property string|null $canonical_url
 * @property string $current_status
 * @property string|null $previous_status
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_indexed_at
 * @property Carbon|null $last_not_indexed_at
 */
final class SeoArticleIndexHealth extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_index_health';

    protected $guarded = [];

    protected $casts = [
        'article_id' => 'integer',
        'site_id' => 'integer',
        'last_checked_at' => 'datetime',
        'last_indexed_at' => 'datetime',
        'last_not_indexed_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
