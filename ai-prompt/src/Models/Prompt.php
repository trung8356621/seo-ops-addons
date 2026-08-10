<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prompt extends Model
{
    use BelongsToOnDefaultConnection;
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    /** @var string Bảng vật lý là `prompts` (không phải `seo_prompts`). */
    protected $table = 'prompts';

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'variables' => 'json',
        'hook_settings' => 'array',
        'hook_version' => 'string',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function aiConnection(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(ApiConnection::class, 'ai_connection_id');
    }

    public function promptResults(): HasMany
    {
        // Explicit FK: SeoPrompt child would otherwise guess seo_prompt_id.
        return $this->hasMany(PromptResult::class, 'prompt_id');
    }
}
