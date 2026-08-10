<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Content\Models\SeoArticle;

final class PublishingArticleState extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'publishing_article_states';

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
