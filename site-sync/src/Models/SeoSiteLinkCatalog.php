<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoSiteLinkCatalog extends Model
{
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_link_catalog';

    protected $fillable = [
        'site_id',
        'wordpress_id',
        'url',
        'url_hash',
        'canonical',
        'slug',
        'title',
        'status',
        'type',
        'content_hash',
        'source',
        'updated_at_wp',
        'inactive_at',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'updated_at_wp' => 'datetime',
        'inactive_at' => 'datetime',
    ];

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }
}
