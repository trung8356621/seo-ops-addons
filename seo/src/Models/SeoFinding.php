<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoFinding extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_findings';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'evidence' => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
