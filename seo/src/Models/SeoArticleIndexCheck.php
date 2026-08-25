<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Immutable Index Health check history row.
 *
 * @property int $id
 * @property int $site_id
 * @property int $article_id
 * @property string $url
 * @property string $status
 * @property string $effective_health
 * @property Carbon $checked_at
 * @property int|null $checked_by
 * @property string $source
 * @property string|null $notes
 * @property array<string, mixed>|null $diagnostics
 */
final class SeoArticleIndexCheck extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_index_checks';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'article_id' => 'integer',
        'checked_by' => 'integer',
        'checked_at' => 'datetime',
        'diagnostics' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
