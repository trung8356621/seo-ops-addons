<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class KeywordWorkspaceMetric extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_workspace_metrics';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'value' => 'integer',
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
