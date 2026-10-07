<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Illuminate\Database\Eloquent\Model;

class MatchResearchCustomConcept extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_match_research_custom_concepts';

    protected $fillable = [
        'site_id',
        'resource_key',
        'source_locale',
        'name',
        'description',
        'positive_examples',
        'negative_examples',
        'enabled',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'positive_examples' => 'array',
        'negative_examples' => 'array',
        'enabled' => 'boolean',
    ];
}
