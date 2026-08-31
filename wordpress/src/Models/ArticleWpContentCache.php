<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Temporary WP content cache for editor open — not articles.body.
 */
final class ArticleWpContentCache extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'article_wp_content_cache';

    protected $fillable = [
        'article_id',
        'wp_post_id',
        'rendered_html',
        'raw_content_json',
        'wp_modified_gmt',
        'wp_content_hash',
        'wp_revision_id',
        'fetched_at',
        'expires_at',
    ];

    protected $casts = [
        'raw_content_json' => 'array',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
        'wp_post_id' => 'integer',
        'wp_revision_id' => 'integer',
    ];
}
