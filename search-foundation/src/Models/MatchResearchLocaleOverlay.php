<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Illuminate\Database\Eloquent\Model;

class MatchResearchLocaleOverlay extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_match_research_locale_overlays';

    protected $fillable = [
        'site_id',
        'resource_key',
        'locale',
        'payload',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'payload' => 'array',
    ];
}
