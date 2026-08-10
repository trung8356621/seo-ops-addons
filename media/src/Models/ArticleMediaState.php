<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Content\Models\SeoArticle;

final class ArticleMediaState extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'article_media_states';

    protected $guarded = [];

    protected $casts = [
        'media_id' => 'integer',
        'position' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
