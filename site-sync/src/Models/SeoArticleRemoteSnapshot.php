<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoArticleRemoteSnapshot extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_remote_snapshots';

    protected $fillable = [
        'site_id',
        'article_id',
        'wordpress_id',
        'content_hash',
        'remote_change_available',
        'payload',
        'remote_modified_at',
    ];

    protected $casts = [
        'remote_change_available' => 'boolean',
        'payload' => 'array',
        'remote_modified_at' => 'datetime',
    ];
}
