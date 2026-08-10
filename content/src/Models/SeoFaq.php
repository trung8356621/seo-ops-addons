<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoFaq extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_faqs';

    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
