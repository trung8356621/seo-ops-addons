<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleMeta extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'article_meta';

    protected $guarded = [];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
