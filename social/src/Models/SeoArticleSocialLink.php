<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Models;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoArticleSocialLink extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_social_links';

    protected $guarded = [];

    protected $casts = [
        'article_id' => 'integer',
        'site_id' => 'integer',
        'created_by' => 'integer',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
