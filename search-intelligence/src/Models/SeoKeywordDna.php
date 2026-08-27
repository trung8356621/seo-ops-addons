<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoKeywordDna extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_dna';

    protected $fillable = [
        'site_id',
        'keyword_id',
        'cluster_key',
        'value',
        'normalized_value',
        'facet_type',
        'confidence',
        'source',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'keyword_id' => 'integer',
    ];
}
