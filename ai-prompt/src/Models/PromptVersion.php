<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a prompt definition used by AI execution.
 */
class PromptVersion extends Model
{
    use \Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'prompt_versions';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'hook_settings' => 'array',
        'settings' => 'array',
        'variables' => 'array',
        'sequence' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            throw new \RuntimeException('PromptVersion is immutable after creation.');
        });
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'prompt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'created_by');
    }

    /**
     * Hydrate a detached SeoPrompt from this immutable version (compile-only).
     */
    public function toCompilePrompt(): SeoPrompt
    {
        $prompt = new SeoPrompt();
        $prompt->exists = false;
        $prompt->setRawAttributes([
            'id' => (int) $this->prompt_id,
            'markdown_content' => (string) ($this->markdown_content ?? ''),
            'hook_key' => $this->hook_key,
            'hook_version' => $this->hook_version,
            'hook_settings' => $this->hook_settings,
            'settings' => $this->settings,
            'variables' => $this->variables,
            'tools' => $this->tools ?? 'default',
        ], true);
        $prompt->syncOriginal();

        return $prompt;
    }
}
