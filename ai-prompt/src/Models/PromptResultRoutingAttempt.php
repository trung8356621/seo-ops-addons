<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical route event for an AI execution (PromptResult).
 * SKIPPED rows are not API attempts (`attempted = false`).
 */
class PromptResultRoutingAttempt extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'prompt_result_routing_attempts';

    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'attempted' => 'boolean',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
        'token_usage' => 'array',
        'raw' => 'array',
    ];

    public function promptResult(): BelongsTo
    {
        return $this->belongsTo(PromptResult::class, 'prompt_result_id');
    }
}
