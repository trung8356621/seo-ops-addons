<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class KeywordLink extends Pivot
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'keyword_link';

    public $incrementing = false;

    protected $casts = [
        'keyword_id' => 'integer',
        'link_id' => 'integer',
        'search_volume' => 'integer',
        'difficulty' => 'integer',
        'metrics' => 'array',
    ];
}
