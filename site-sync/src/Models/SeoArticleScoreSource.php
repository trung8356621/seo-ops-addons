<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoArticleScoreSource extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_score_sources';

    protected $fillable = [
        'site_id',
        'article_id',
        'wordpress_id',
        'source',
        'score',
        'raw',
    ];

    protected $casts = [
        'score' => 'float',
        'raw' => 'array',
    ];
}
