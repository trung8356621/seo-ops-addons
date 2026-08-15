<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Content\Models\SeoArticle;

final class SeoArticleProfile extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_profiles';

    protected $guarded = [];

    protected $casts = [
        'seo_score' => 'decimal:2',
        'skip_seo_score' => 'boolean',
        'internal_link_count' => 'integer',
        'external_link_count' => 'integer',
        'indexed_at' => 'datetime',
        'previous_indexed_at' => 'datetime',
        'is_indexable' => 'boolean',
        'is_followable' => 'boolean',
        'raw_meta' => 'array',
        'synced_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
