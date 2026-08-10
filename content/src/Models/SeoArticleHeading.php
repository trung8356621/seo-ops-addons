<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Heading (TOC / dàn ý) bóc tách từ nội dung bài viết.
 * Bảng phẳng `seo_article_headings`, connection `omi_seo_ai`.
 */
class SeoArticleHeading extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_headings';

    protected $guarded = [];

    protected $casts = [
        'article_id' => 'integer',
        'level' => 'integer',
        'sort_order' => 'integer',
        'parent_id' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
