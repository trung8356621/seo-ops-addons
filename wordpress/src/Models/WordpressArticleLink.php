<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Content\Models\SeoArticle;

final class WordpressArticleLink extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'wordpress_article_links';

    protected $guarded = [];

    protected $casts = [
        'wp_post_id' => 'integer',
        'sync_job_id' => 'integer',
        'site_id' => 'integer',
        'last_synced_at' => 'datetime',
        'external_modified_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
