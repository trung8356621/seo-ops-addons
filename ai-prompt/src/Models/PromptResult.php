<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptResult extends Model
{
    /** @var list<string> Columns for History list — never hydrates output_text. */
    public const HOT_COLUMNS = [
        'id',
        'prompt_id',
        'prompt_version_id',
        'canonical_prompt_key',
        'stage',
        'status',
        'input_snapshot',
        'token_usage',
        'error_message',
        'compiled_prompt_hash',
        'content_project_id',
        'project_item_id',
        'run_id',
        'node_id',
        'retry_attempt',
        'correlation_id',
        'failure_category',
        'failure_code',
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
        'user_id',
        'site_id',
    ];

    protected $connection = 'omi_seo_ai';

    protected $guarded = [];

    protected $casts = [
        'input_snapshot' => 'array',
        'token_usage'    => 'array',
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
        'retry_attempt'  => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $result): void {
            app(\Omnichannel\Addons\AiPrompt\Services\PromptExecutionPersistence::class)
                ->normalizeBeforeSave($result);
        });

        static::saved(function (self $result): void {
            app(\Omnichannel\Addons\AiPrompt\Services\PromptExecutionPersistence::class)
                ->syncRoutingAttempts($result);
        });
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'prompt_version_id');
    }

    public function routingAttempts(): HasMany
    {
        return $this->hasMany(PromptResultRoutingAttempt::class, 'prompt_result_id')
            ->orderBy('sequence');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
