<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Illuminate\Database\Eloquent\Model;

class SeoLinkAudit extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_link_audits';

    protected $fillable = [
        'site_id',
        'target_url_hash',
        'target_url',
        'status',
        'last_http_status',
        'last_audited_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'status' => SeoLinkMapStatus::class,
            'last_http_status' => 'integer',
            'last_audited_at' => 'datetime',
        ];
    }
}
