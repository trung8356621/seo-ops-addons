<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteLinkExclusion extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_link_exclusions';

    protected $fillable = [
        'site_id',
        'url',
        'url_hash',
        'wordpress_id',
        'reason',
    ];
}
