<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Illuminate\Database\Eloquent\Model;

class SeoArticleCtaRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_cta_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'plan' => 'array',
        'changes' => 'array',
        'review' => 'array',
        'summary' => 'array',
        'selections' => 'array',
        'generation_started_at' => 'datetime',
        'generation_completed_at' => 'datetime',
        'applied_at' => 'datetime',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'connection_id' => 'integer',
        'prompt_result_id' => 'integer',
    ];
}
